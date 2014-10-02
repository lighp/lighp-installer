<?php
if (!ini_get('display_errors')) {
	ini_set('display_errors', 1);
}
error_reporting(E_ALL);

//Define max. execution time
if(!ini_get('safe_mode')) { //Detect safe_mode, but sometimes it doesn't work well -> we use the @ operator
	@set_time_limit(300); // 5min
}

$view = array(
	'title' => 'Lighp installer',
	'installing?' => false,
	'finished?' => false
);

function defaultRoot() {
	$path = $_SERVER['REQUEST_URI'];
	$scriptName = basename(__FILE__);

	if (substr($path, - strlen($scriptName)) == $scriptName) {
		$path = substr($path, 0, - strlen($scriptName));
	}
	if (substr($path, -1) == '/') {
		$path = substr($path, 0, -1);
	}

	return $path;
}

function tmpPath() {
	return tempnam(sys_get_temp_dir(), 'lighp');
}

/**
 * Copy a file, or recursively copy a folder and its contents
 * @param       string   $source    Source path
 * @param       string   $dest      Destination path
 * @param       string   $permissions New folder creation permissions
 * @return      bool     Returns true on success, false on failure
 * @see https://stackoverflow.com/questions/2050859/copy-entire-contents-of-a-directory-to-another-using-php
 */
function xcopy($source, $dest, $permissions = 0755) {
	// Check for symlinks
	if (is_link($source)) {
		return symlink(readlink($source), $dest);
	}

	// Simple copy for a file
	if (is_file($source)) {
		return copy($source, $dest);
	}

	// Make destination directory
	if (!is_dir($dest)) {
		mkdir($dest, $permissions);
	}

	// Loop through the folder
	$dir = dir($source);
	while (false !== $entry = $dir->read()) {
		// Skip pointers
		if ($entry == '.' || $entry == '..') {
			continue;
		}

		// Deep copy directories
		xcopy($source.DIRECTORY_SEPARATOR.$entry, $dest.DIRECTORY_SEPARATOR.$entry);
	}

	// Clean up
	$dir->close();
	return true;
}
function xrm($file) {
	// Simple copy for a file
	if (is_file($file)) {
		return unlink($file);
	}

	// Loop through the folder
	$dir = dir($file);
	while (false !== $entry = $dir->read()) {
		// Skip pointers
		if ($entry == '.' || $entry == '..') {
			continue;
		}

		// Deep copy directories
		xrm($file.DIRECTORY_SEPARATOR.$entry);
	}

	// Clean up
	$dir->close();
	rmdir($file);
	return true;
}

$steps = array(
	array(
		'description' => 'Downloading and extracting Lighp...',
		'worker' => function () {
			$downloadUrl = 'https://github.com/lighp/lighp/archive/master.zip';
			$zipPath = tmpPath();
			$destPath = dirname(__FILE__);

			if (copy($downloadUrl, $zipPath) === false) {
				throw new Exception('Cannot download Lighp');
			}

			$zip = new ZipArchive;
			if ($zip->open($zipPath) !== true) {
				throw new Exception('Cannot open downloaded zip file');
			}

			if (!$zip->extractTo($destPath)) {
				throw new Exception('Cannot extract downloaded zip file');
			}
			$zip->close();

			$extractPath = $destPath.DIRECTORY_SEPARATOR.'lighp-master';
			xcopy($extractPath, $destPath, 0777);
			xrm($extractPath);

			// Rename .htaccess to prevent it from rewriting URLs
			rename($destPath.DIRECTORY_SEPARATOR.'.htaccess', $destPath.DIRECTORY_SEPARATOR.'.htaccess.new');
		}
	),
	array(
		'description' => 'Downloading Composer...',
		'worker' => function () {
			$downloadUrl = 'https://getcomposer.org/composer.phar';
			$destPath = dirname(__FILE__).DIRECTORY_SEPARATOR.'composer.phar';

			if (copy($downloadUrl, $destPath) === false) {
				throw new Exception('Cannot download Composer');
			}
		}
	),
	array(
		'description' => 'Downloading Lighp dependencies (this might take some time)...',
		'worker' => function () {
			// Create tmp home
			$composerHome = dirname(__FILE__).DIRECTORY_SEPARATOR.'composer';
			if (!is_dir($composerHome)) {
				mkdir($composerHome);
			}

			$phpPath = 'php';
			if (stristr(PHP_OS, 'WIN')) { // Workaround for WAMP
				$wampPhpPath = 'C:\wamp\bin\php\php'.PHP_VERSION.'\php.exe';
				if (file_exists($wampPhpPath)) {
					$phpPath = $wampPhpPath;
				}
			} 

			putenv('COMPOSER_HOME='.$composerHome);

			// Prefer dist because sometimes Git is not installed (especially on Windows)
			exec($phpPath.' composer.phar -n --prefer-dist install', $output, $returnVal);

			if ($returnVal != 0) { // Something went wrong
				throw new Exception('Cannot install Lighp dependencies ['.$returnVal.']:<pre>'."\n".implode("\n", $output).'</pre>');
			}

			// Composer creates .htaccess sometimes, with "Deny from all" in it
			if (file_exists('.htaccess')) {
				unlink('.htaccess');
			}

			xrm($composerHome);
		}
	),
	array(
		'description' => 'Writing configuration file...',
		'worker' => function () {
			$configPath = implode(DIRECTORY_SEPARATOR, array(
				dirname(__FILE__),
				'etc', 'core', 'website.json'
			));
			
			$config = json_decode(file_get_contents($configPath), true);

			$config['name'] = $_GET['name'];
			$config['description'] = $_GET['description'];
			$config['author'] = $_GET['author'];
			$config['root'] = $_GET['root'];

			if (file_put_contents($configPath, json_encode($config)) === false) {
				throw new Exception('Cannot write configuration file');
			}
		}
	),
	array(
		'description' => 'Setting up .htaccess...',
		'worker' => function () {
			$dirname = dirname(__FILE__).DIRECTORY_SEPARATOR;
			if (rename($dirname.'.htaccess.new', $dirname.'.htaccess') === false) {
				throw new Exception('Cannot move .htaccess.new to .htaccess');
			}
		}
	)
);

$view['stepsNbr'] = count($steps);

$currentStep = (isset($_GET['step'])) ? (int) $_GET['step'] : -1;
$processStep = function () use(&$steps, $currentStep) {
	return $steps[$currentStep]['worker']();
};

$nextStep = function () use(&$view, &$steps, $currentStep) {
	if (!isset($steps[$currentStep + 1])) {
		return;
	}

	$data = $_GET;
	$data['step'] = $currentStep + 1;
	$view['redirect'] = '?'.http_build_query($data);
};

if (isset($_GET['install'])) {
	$view['installing?'] = true;
	$view['step'] = $currentStep + 1;
	$view['progress'] = round($view['step'] / count($steps) * 100);

	if (isset($steps[$currentStep + 1])) {
		$view['installMsg'] = $steps[$currentStep + 1]['description'];
		$view['title'] = $view['progress'].'% - '.$view['title'];
	} else {
		$view['finished?'] = true;
		$view['title'] = 'Finished - '.$view['title'];
	}

	if ($currentStep >= 0) {
		try {
			$processStep();
		} catch (Exception $e) {
			$view['title'] = 'Error - '.$view['title'];
			$view['error'] = $e->getMessage();
		}
	}

	if (!isset($view['error'])) {
		$nextStep();
	}
}

if (isset($_GET['root'])) {
	$view['root'] = $_GET['root'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title><?php echo $view['title']; ?></title>

	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.2.0/css/bootstrap.min.css">
	<!--<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.2.0/css/bootstrap-theme.min.css">-->
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootswatch/3.2.0/cyborg/bootstrap.min.css">

	<?php
	if (isset($view['redirect'])) {
		?>
		<meta http-equiv="refresh" content="0; url=<?php echo $view['redirect']; ?>">
		<?php
	}
	?>
</head>
<body>
	<div class="container">
		<h1 class="text-center">
			<img src="data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiIHN0YW5kYWxvbmU9Im5vIj8+CjwhLS0gQ3JlYXRlZCB3aXRoIElua3NjYXBlIChodHRwOi8vd3d3Lmlua3NjYXBlLm9yZy8pIC0tPgoKPHN2ZwogICB4bWxuczpkYz0iaHR0cDovL3B1cmwub3JnL2RjL2VsZW1lbnRzLzEuMS8iCiAgIHhtbG5zOmNjPSJodHRwOi8vY3JlYXRpdmVjb21tb25zLm9yZy9ucyMiCiAgIHhtbG5zOnJkZj0iaHR0cDovL3d3dy53My5vcmcvMTk5OS8wMi8yMi1yZGYtc3ludGF4LW5zIyIKICAgeG1sbnM6c3ZnPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIKICAgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIgogICB4bWxuczpzb2RpcG9kaT0iaHR0cDovL3NvZGlwb2RpLnNvdXJjZWZvcmdlLm5ldC9EVEQvc29kaXBvZGktMC5kdGQiCiAgIHhtbG5zOmlua3NjYXBlPSJodHRwOi8vd3d3Lmlua3NjYXBlLm9yZy9uYW1lc3BhY2VzL2lua3NjYXBlIgogICB3aWR0aD0iMjUwIgogICBoZWlnaHQ9IjI1MCIKICAgaWQ9InN2ZzIiCiAgIHZlcnNpb249IjEuMSIKICAgaW5rc2NhcGU6dmVyc2lvbj0iMC40OC40IHI5OTM5IgogICBzb2RpcG9kaTpkb2NuYW1lPSJOb3V2ZWF1IGRvY3VtZW50IDEiPgogIDxkZWZzCiAgICAgaWQ9ImRlZnM0IiAvPgogIDxzb2RpcG9kaTpuYW1lZHZpZXcKICAgICBpZD0iYmFzZSIKICAgICBwYWdlY29sb3I9IiNmZmZmZmYiCiAgICAgYm9yZGVyY29sb3I9IiM2NjY2NjYiCiAgICAgYm9yZGVyb3BhY2l0eT0iMS4wIgogICAgIGlua3NjYXBlOnBhZ2VvcGFjaXR5PSIwLjAiCiAgICAgaW5rc2NhcGU6cGFnZXNoYWRvdz0iMiIKICAgICBpbmtzY2FwZTp6b29tPSIwLjciCiAgICAgaW5rc2NhcGU6Y3g9IjI2Ny42OTgxIgogICAgIGlua3NjYXBlOmN5PSIyMjEuODEwMyIKICAgICBpbmtzY2FwZTpkb2N1bWVudC11bml0cz0icHgiCiAgICAgaW5rc2NhcGU6Y3VycmVudC1sYXllcj0ibGF5ZXIxIgogICAgIHNob3dncmlkPSJmYWxzZSIKICAgICBpbmtzY2FwZTp3aW5kb3ctd2lkdGg9IjEwNTAiCiAgICAgaW5rc2NhcGU6d2luZG93LWhlaWdodD0iMTY0MSIKICAgICBpbmtzY2FwZTp3aW5kb3cteD0iMTY4MCIKICAgICBpbmtzY2FwZTp3aW5kb3cteT0iMCIKICAgICBpbmtzY2FwZTp3aW5kb3ctbWF4aW1pemVkPSIxIiAvPgogIDxtZXRhZGF0YQogICAgIGlkPSJtZXRhZGF0YTciPgogICAgPHJkZjpSREY+CiAgICAgIDxjYzpXb3JrCiAgICAgICAgIHJkZjphYm91dD0iIj4KICAgICAgICA8ZGM6Zm9ybWF0PmltYWdlL3N2Zyt4bWw8L2RjOmZvcm1hdD4KICAgICAgICA8ZGM6dHlwZQogICAgICAgICAgIHJkZjpyZXNvdXJjZT0iaHR0cDovL3B1cmwub3JnL2RjL2RjbWl0eXBlL1N0aWxsSW1hZ2UiIC8+CiAgICAgICAgPGRjOnRpdGxlPjwvZGM6dGl0bGU+CiAgICAgIDwvY2M6V29yaz4KICAgIDwvcmRmOlJERj4KICA8L21ldGFkYXRhPgogIDxnCiAgICAgaW5rc2NhcGU6bGFiZWw9IkNhbHF1ZSAxIgogICAgIGlua3NjYXBlOmdyb3VwbW9kZT0ibGF5ZXIiCiAgICAgaWQ9ImxheWVyMSIKICAgICB0cmFuc2Zvcm09InRyYW5zbGF0ZSgwLC04MDIuMzYyMTgpIj4KICAgIDx0ZXh0CiAgICAgICB4bWw6c3BhY2U9InByZXNlcnZlIgogICAgICAgc3R5bGU9ImZvbnQtc2l6ZTo0MHB4O2ZvbnQtc3R5bGU6bm9ybWFsO2ZvbnQtd2VpZ2h0Om5vcm1hbDtsaW5lLWhlaWdodDoxMjUlO2xldHRlci1zcGFjaW5nOjBweDt3b3JkLXNwYWNpbmc6MHB4O2ZpbGw6IzAwMDAwMDtmaWxsLW9wYWNpdHk6MTtzdHJva2U6bm9uZTtmb250LWZhbWlseTpTYW5zIgogICAgICAgeD0iODQuNDExNjIxIgogICAgICAgeT0iMTAzOS4yMzk2IgogICAgICAgaWQ9InRleHQyOTg1IgogICAgICAgc29kaXBvZGk6bGluZXNwYWNpbmc9IjEyNSUiPjx0c3BhbgogICAgICAgICBzb2RpcG9kaTpyb2xlPSJsaW5lIgogICAgICAgICBpZD0idHNwYW4yOTg3IgogICAgICAgICB4PSI4NC40MTE2MjEiCiAgICAgICAgIHk9IjEwMzkuMjM5NiIKICAgICAgICAgc3R5bGU9ImZvbnQtc2l6ZToyNTBweDtmb250LXN0eWxlOm5vcm1hbDtmb250LXZhcmlhbnQ6bm9ybWFsO2ZvbnQtd2VpZ2h0Om5vcm1hbDtmb250LXN0cmV0Y2g6bm9ybWFsO2ZpbGw6IzRmNWI5ZjtmaWxsLW9wYWNpdHk6MC45NDExNzY0NztzdHJva2U6bm9uZTtmb250LWZhbWlseTpRdWludGVzc2VudGlhbDstaW5rc2NhcGUtZm9udC1zcGVjaWZpY2F0aW9uOlF1aW50ZXNzZW50aWFsIj5sPC90c3Bhbj48L3RleHQ+CiAgPC9nPgo8L3N2Zz4K" alt="Logo" style="width:256px;"><br>
			Lighp installer
		</h1>

		<?php
		if (!$view['installing?']) {
			?>
			<form action="" method="get" class="form-horizontal">
				<input type="hidden" name="install" value="1">

				<div class="form-group">
					<label for="name" class="col-sm-2 control-label">Name</label>
					<div class="col-sm-10">
						<input type="text" class="form-control" id="name" name="name" placeholder="Name of your application">
					</div>
				</div>

				<div class="form-group">
					<label for="description" class="col-sm-2 control-label">Description</label>
					<div class="col-sm-10">
						<input type="text" class="form-control" id="description" name="description" placeholder="Short description">
					</div>
				</div>

				<div class="form-group">
					<label for="author" class="col-sm-2 control-label">Author</label>
					<div class="col-sm-10">
						<input type="text" class="form-control" id="author" name="author" placeholder="Author">
					</div>
				</div>

				<div class="form-group">
					<label for="root" class="col-sm-2 control-label">Root path</label>
					<div class="col-sm-10">
						<input type="text" class="form-control" id="root" name="root" placeholder="Root path" value="<?php echo defaultRoot(); ?>">
						<span class="help-block">Leave this value as is if you don't know what it is.</span>
					</div>
				</div>

				<div class="form-group">
					<div class="col-sm-offset-2 col-sm-10">
						<button type="submit" class="btn btn-lg btn-primary">Install</button>
					</div>
				</div>
			</form>
			<?php
		} else {
			if (isset($view['progress'])) {
				?>
				<div class="progress">
					<div class="progress-bar" role="progressbar" aria-valuenow="<?php echo $view['progress']; ?>" aria-valuemin="0" aria-valuemax="100" style="width: <?php echo $view['progress']; ?>%;"></div>
				</div>
				<?php
			}

			if (isset($view['error'])) {
				?>
				<p class="alert alert-danger"><strong>Error!</strong> <?php echo $view['error']; ?></p>
				<?php
			} elseif (!$view['finished?']) {
				?>
				<p><?php echo $view['installMsg']; ?></p>
				<?php
			} else {
				?>
				<p class="alert alert-success">
					<strong>Finished!</strong> You can now delete this file <code><?php echo basename(__FILE__); ?></code> and use your new Lighp installation.<br>
					You should change your admin credentials. By default, the username is <em>admin</em> and the password is <em>admin</em>.
				</p>
				<a href="<?php echo $view['root'].'/admin'; ?>" target="_blank" class="btn btn-primary">Open admin</a>
				<?php
			}
		}
		?>
	</div>

	<footer class="container">
		<hr>

		<small class="pull-right"><a href="https://github.com/lighp/lighp/wiki/Installing_fr">Installation guide</a></small>

		<small><a href="http://lighp.github.io/lighp">Lighp</a> by <a href="http://emersion.fr">Emersion</a></small>
	</footer>
</body>
</html>