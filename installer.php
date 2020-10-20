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

function defaultDownloadUrl() {
	return 'https://github.com/lighp/lighp/archive/master.zip';
}

function tmpPath() {
	return tempnam(sys_get_temp_dir(), 'lighp');
}

/**
 * Copy a file, or recursively copy a folder and its contents
 * @param       string   $source    Source path
 * @param       string   $dest      Destination path
 * @param       string   $permissions New files permissions
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
		$result = copy($source, $dest);
		if ($result === false) {
			return false;
		}
		chmod($dest, $permissions);
		return true;
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
		
		$childSource = $source.DIRECTORY_SEPARATOR.$entry;
		$childDest = $dest.DIRECTORY_SEPARATOR.$entry;
		
		// Empty destination if it is a directory
		if (is_dir($childDest)) {
			xrm($childDest);
		}

		// Deep copy directories
		xcopy($childSource, $childDest);
	}

	// Clean up
	$dir->close();
	return true;
}

function xrm($file) {
	if (!file_exists($file)) {
		return false;
	}

	// Simple deletion for a file
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

		// Deep delete directories
		xrm($file.DIRECTORY_SEPARATOR.$entry);
	}

	// Clean up
	$dir->close();
	rmdir($file);
	return true;
}

function pkgsInstalled() {
	return file_exists(dirname(__FILE__).DIRECTORY_SEPARATOR.'composer.lock');
}

$steps = array(
	array(
		'description' => 'Downloading and extracting Lighp...',
		'worker' => function () {
			$downloadUrl = $_GET['downloadUrl'];
			$zipPath = tmpPath();
			$destPath = dirname(__FILE__);
			$extractPath = $destPath.DIRECTORY_SEPARATOR.'lighp-master';

			if (copy($downloadUrl, $zipPath) === false) {
				throw new Exception('Cannot download Lighp');
			}

			if (file_exists($extractPath)) {
				xrm($extractPath);
			}

			$zip = new ZipArchive;
			if ($zip->open($zipPath) !== true) {
				throw new Exception('Cannot open downloaded zip file');
			}

			if (!$zip->extractTo($destPath)) {
				throw new Exception('Cannot extract downloaded zip file');
			}
			$zip->close();

			xcopy($extractPath, $destPath, 0777);
			xrm($extractPath);

			// Rename .htaccess to prevent it from rewriting URLs
			rename($destPath.DIRECTORY_SEPARATOR.'.htaccess', $destPath.DIRECTORY_SEPARATOR.'.htaccess.new');
		}
	),
	array(
		'description' => 'Downloading Composer...',
		'worker' => function () {
			if (pkgsInstalled() && (!isset($_GET['updatePkgs']) || $_GET['updatePkgs'] != 'on')) {
				return;
			}

			$downloadUrl = 'https://getcomposer.org/composer-1.phar';
			$destPath = dirname(__FILE__).DIRECTORY_SEPARATOR.'composer.phar';

			if (copy($downloadUrl, $destPath) === false) {
				throw new Exception('Cannot download Composer');
			}
		}
	),
	array(
		'description' => 'Downloading Lighp dependencies (this might take some time)...',
		'worker' => function () {
			if (pkgsInstalled() && (!isset($_GET['updatePkgs']) || $_GET['updatePkgs'] != 'on')) {
				return;
			}

			// Create tmp home
			$composerHome = dirname(__FILE__).DIRECTORY_SEPARATOR.'composer';
			mkdir($composerHome);

			putenv('COMPOSER_HOME='.$composerHome);
			putenv('COMPOSER_LIGHP_FORCE_OVERWRITE=true');
			exec('php composer.phar -n update --prefer-dist', $output, $returnVal);

			if ($returnVal != 0) { // Something went wrong
				throw new Exception('Cannot install Lighp dependencies ['.$returnVal.']:'."\n".implode("\n", $output));
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

if (isset($_GET['install']) && (int) $_GET['install']) {
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
			<img src="data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiIHN0YW5kYWxvbmU9Im5vIj8+CjwhLS0gQ3JlYXRlZCB3aXRoIElua3NjYXBlIChodHRwOi8vd3d3Lmlua3NjYXBlLm9yZy8pIC0tPgoKPHN2ZwogICB4bWxuczpkYz0iaHR0cDovL3B1cmwub3JnL2RjL2VsZW1lbnRzLzEuMS8iCiAgIHhtbG5zOmNjPSJodHRwOi8vY3JlYXRpdmVjb21tb25zLm9yZy9ucyMiCiAgIHhtbG5zOnJkZj0iaHR0cDovL3d3dy53My5vcmcvMTk5OS8wMi8yMi1yZGYtc3ludGF4LW5zIyIKICAgeG1sbnM6c3ZnPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIKICAgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIgogICB4bWxuczpzb2RpcG9kaT0iaHR0cDovL3NvZGlwb2RpLnNvdXJjZWZvcmdlLm5ldC9EVEQvc29kaXBvZGktMC5kdGQiCiAgIHhtbG5zOmlua3NjYXBlPSJodHRwOi8vd3d3Lmlua3NjYXBlLm9yZy9uYW1lc3BhY2VzL2lua3NjYXBlIgogICB3aWR0aD0iMjUwIgogICBoZWlnaHQ9IjI1MCIKICAgaWQ9InN2ZzIiCiAgIHZlcnNpb249IjEuMSIKICAgaW5rc2NhcGU6dmVyc2lvbj0iMC40OC41IHIxMDA0MCIKICAgc29kaXBvZGk6ZG9jbmFtZT0ibGlnaHAtbG9nby5zdmciPgogIDxkZWZzCiAgICAgaWQ9ImRlZnM0IiAvPgogIDxzb2RpcG9kaTpuYW1lZHZpZXcKICAgICBpZD0iYmFzZSIKICAgICBwYWdlY29sb3I9IiNmZmZmZmYiCiAgICAgYm9yZGVyY29sb3I9IiM2NjY2NjYiCiAgICAgYm9yZGVyb3BhY2l0eT0iMS4wIgogICAgIGlua3NjYXBlOnBhZ2VvcGFjaXR5PSIwLjAiCiAgICAgaW5rc2NhcGU6cGFnZXNoYWRvdz0iMiIKICAgICBpbmtzY2FwZTp6b29tPSIwLjciCiAgICAgaW5rc2NhcGU6Y3g9IjI2Ny42OTgxIgogICAgIGlua3NjYXBlOmN5PSIyMjEuODEwMyIKICAgICBpbmtzY2FwZTpkb2N1bWVudC11bml0cz0icHgiCiAgICAgaW5rc2NhcGU6Y3VycmVudC1sYXllcj0ibGF5ZXIxIgogICAgIHNob3dncmlkPSJmYWxzZSIKICAgICBpbmtzY2FwZTp3aW5kb3ctd2lkdGg9IjEwNTAiCiAgICAgaW5rc2NhcGU6d2luZG93LWhlaWdodD0iMTY0MSIKICAgICBpbmtzY2FwZTp3aW5kb3cteD0iMTY4MCIKICAgICBpbmtzY2FwZTp3aW5kb3cteT0iMCIKICAgICBpbmtzY2FwZTp3aW5kb3ctbWF4aW1pemVkPSIxIiAvPgogIDxtZXRhZGF0YQogICAgIGlkPSJtZXRhZGF0YTciPgogICAgPHJkZjpSREY+CiAgICAgIDxjYzpXb3JrCiAgICAgICAgIHJkZjphYm91dD0iIj4KICAgICAgICA8ZGM6Zm9ybWF0PmltYWdlL3N2Zyt4bWw8L2RjOmZvcm1hdD4KICAgICAgICA8ZGM6dHlwZQogICAgICAgICAgIHJkZjpyZXNvdXJjZT0iaHR0cDovL3B1cmwub3JnL2RjL2RjbWl0eXBlL1N0aWxsSW1hZ2UiIC8+CiAgICAgICAgPGRjOnRpdGxlPjwvZGM6dGl0bGU+CiAgICAgIDwvY2M6V29yaz4KICAgIDwvcmRmOlJERj4KICA8L21ldGFkYXRhPgogIDxnCiAgICAgaW5rc2NhcGU6bGFiZWw9IkNhbHF1ZSAxIgogICAgIGlua3NjYXBlOmdyb3VwbW9kZT0ibGF5ZXIiCiAgICAgaWQ9ImxheWVyMSIKICAgICB0cmFuc2Zvcm09InRyYW5zbGF0ZSgwLC04MDIuMzYyMTgpIj4KICAgIDxnCiAgICAgICBzdHlsZT0iZm9udC1zaXplOjQwcHg7Zm9udC1zdHlsZTpub3JtYWw7Zm9udC13ZWlnaHQ6bm9ybWFsO2xpbmUtaGVpZ2h0OjEyNSU7bGV0dGVyLXNwYWNpbmc6MHB4O3dvcmQtc3BhY2luZzowcHg7ZmlsbDojMDAwMDAwO2ZpbGwtb3BhY2l0eToxO3N0cm9rZTpub25lO2ZvbnQtZmFtaWx5OlNhbnMiCiAgICAgICBpZD0idGV4dDI5ODUiPgogICAgICA8cGF0aAogICAgICAgICBkPSJtIDE1Ni4xODg5NiwxMDA3LjI1NzIgYyAtMS42Mjc2NywyLjExNTkgLTMuNDU4NzIsNC40NzU5IC01LjQ5MzE2LDcuMDgwMSAtMS45NTMxOSwyLjUyMjggLTQuMDY5MDcsNS4xMjcgLTYuMzQ3NjYsNy44MTI1IC0yLjI3ODcsMi42ODU1IC00LjY3OTQxLDUuMjg5NyAtNy4yMDIxNCw3LjgxMjUgLTIuNTIyODQsMi40NDE0IC01LjE2NzY5LDQuNjM4NyAtNy45MzQ1Nyw2LjU5MTggLTIuNjg1NTksMS44NzE3IC01LjQ1MjUyLDMuMzM2NiAtOC4zMDA3OSw0LjM5NDUgLTIuODQ4MzQsMS4wNTc5IC01LjY5NjY0LDEuNTA1NSAtOC41NDQ5MiwxLjM0MjggLTIuNDQxNDMsLTAuMjQ0MiAtNC40MzUyNCwtMS4zNDI4IC01Ljk4MTQ0LC0zLjI5NTkgLTEuNTQ2MjUsLTIuMDM0NSAtMi43NjY5NSwtNC41OTggLTMuNjYyMTEsLTcuNjkwNCAtMC44OTUyLC0zLjE3MzkgLTEuNDY0ODYsLTYuNzk1MyAtMS43MDg5OSwtMTAuODY0MyAtMC4yNDQxNSwtNC4wNjkgLTAuMjg0ODQsLTguMzAwOCAtMC4xMjIwNywtMTIuNjk1MyAwLjA4MTQsLTIuMTk3MiAwLjI4NDgyLC02LjA2MjggMC42MTAzNSwtMTEuNTk2NyAwLjMyNTUxLC01LjYxNTE4IDAuNjkxNzIsLTEyLjI0NzY2IDEuMDk4NjQsLTE5Ljg5NzQ2IDAuNDg4MjYsLTcuNjQ5NjcgMC45NzY1NCwtMTYuMDMxODIgMS40NjQ4NCwtMjUuMTQ2NDggMC41Njk2NCwtOS4xMTQ0OSAxLjA5ODYxLC0xOC4zNTExMyAxLjU4NjkyLC0yNy43MDk5NiAwLjQ4ODI1LC05LjM1ODYgMC45NzY1NCwtMTguNTEzODcgMS40NjQ4NCwtMjcuNDY1ODIgMC40ODgyNiwtOS4wMzMwNSAwLjg5NTE2LC0xNy4yMTE3NiAxLjIyMDcsLTI0LjUzNjE0IDAuNDA2ODgsLTcuMzI0MDQgMC43MzI0LC0xMy41MDg5MyAwLjk3NjU2LC0xOC41NTQ2OCAwLjI0NDEyLC01LjEyNjc2IDAuMzY2MTksLTguNTA0MDQgMC4zNjYyMiwtMTAuMTMxODQgLTNlLTUsLTQuMzk0MzMgLTAuMzY2MjQsLTcuODEyMjkgLTEuMDk4NjQsLTEwLjI1MzkxIC0wLjY1MTA2LC0yLjQ0MTE5IC0yLjM2MDA0LC0zLjY2MTg5IC01LjEyNjk1LC0zLjY2MjEgLTIuNjg1NTYsMi4xZS00IC02LjYzMjUsMS4yMjA5MSAtMTEuODQwODIsMy42NjIxIDMuNTgwNzE4LC0zLjA5MjIzIDYuNjczMTYzLC01LjczNzA5IDkuMjc3MzQsLTcuOTM0NTcgMi42MDQxNSwtMi4xOTcwNCA0Ljc2MDcyLC0zLjk4NzQxIDYuNDY5NzMsLTUuMzcxMDkgMi4wMzQ0OCwtMS41NDYgMy43MDI3NywtMi44NDgwOCA1LjAwNDg4LC0zLjkwNjI1IDIuNDQxMzgsLTIuMDM0MjggNS4xMjY5MiwtMy4wNTE1MyA4LjA1NjY0LC0zLjA1MTc2IDIuNTIyNzUsMi4zZS00IDQuNjc5MzIsMC45MzYxIDYuNDY5NzMsMi44MDc2MiAxLjg3MTcsMS44NzE5NyAyLjY0NDgxLDQuODAxNjUgMi4zMTkzNCw4Ljc4OTA2IC0wLjA4MTQsMS41NDY0NCAtMC4yODQ4OCw0Ljg4MzAzIC0wLjYxMDM2LDEwLjAwOTc3IC0wLjI0NDE4LDUuMDQ1NzcgLTAuNTI5MDEsMTEuMzEyMDQgLTAuODU0NDksMTguNzk4ODMgLTAuMzI1NTYsNy40MDU3NyAtMC43MzI0NiwxNS43MDY1NSAtMS4yMjA3LDI0LjkwMjM0IC0wLjQwNjk0LDkuMTE0NzQgLTAuODEzODQsMTguNTU0ODMgLTEuMjIwNywyOC4zMjAzMSAtMC40MDY5NSw5LjY4NDM3IC0wLjg1NDU0LDE5LjM2ODYxIC0xLjM0Mjc4LDI5LjA1Mjc0IC0wLjQwNjk0LDkuNjAyOTYgLTAuNzczMTUsMTguNTU0NzcgLTEuMDk4NjMsMjYuODU1NDcgLTAuMzI1NTYsOC4zMDA4NSAtMC42MTAzOSwxNS42NjU3NSAtMC44NTQ0OSwyMi4wOTQ3MiAtMC4xNjI4LDYuNDI5MDkgLTAuMjg0ODcsMTEuMzExOSAtMC4zNjYyMSwxNC42NDg0NCAtMC4wODE0LDQuNTU3MzYgMC4xNjI3Miw4LjQ2MzU2IDAuNzMyNDIsMTEuNzE4NzYgMC41Njk2MiwzLjI1NTIgMS4zNDI3Myw1LjkwMDEgMi4zMTkzMyw3LjkzNDYgMC45NzY1MywyLjAzNDUgMi4xNTY1NCwzLjU0IDMuNTQwMDQsNC41MTY2IDEuMzgzNDIsMC44OTUyIDIuODQ4MjYsMS4zNDI4IDQuMzk0NTMsMS4zNDI3IDEuODcxNywxMGUtNSAzLjc0MzQ0LC0wLjU2OTYgNS42MTUyNCwtMS43MDg5IDEuODcxNjksLTEuMTM5NCAzLjY2MjA1LC0yLjYwNDIgNS4zNzEwOSwtNC4zOTQ2IDEuNzA4OTMsLTEuNzkwMyAzLjMzNjUzLC0zLjgyNDggNC44ODI4MiwtNi4xMDM1IDEuNTQ2MTUsLTIuMjc4NiAyLjk3MDMxLC00LjU1NzMgNC4yNzI0NiwtNi44MzU5IDEuMzAyMDEsLTIuMjc4NiAyLjQwMDY0LC00LjQzNTIgMy4yOTU4OSwtNi40Njk3NiAwLjk3NjQ5LC0yLjAzNDQ2IDEuNzQ5NjEsLTMuNzAyNzYgMi4zMTkzNCwtNS4wMDQ4OCBsIC0yLjE5NzI3LDEzLjc5Mzk0IgogICAgICAgICBzdHlsZT0iZm9udC1zaXplOjI1MHB4O2ZvbnQtdmFyaWFudDpub3JtYWw7Zm9udC1zdHJldGNoOm5vcm1hbDtmaWxsLW9wYWNpdHk6MC45NDExNzY0Nztmb250LWZhbWlseTpRdWludGVzc2VudGlhbDstaW5rc2NhcGUtZm9udC1zcGVjaWZpY2F0aW9uOlF1aW50ZXNzZW50aWFsO2ZpbGw6IzRmNWI5ZiIKICAgICAgICAgaWQ9InBhdGgzMDUwIiAvPgogICAgPC9nPgogIDwvZz4KPC9zdmc+Cg==" alt="Logo" style="width:256px;"><br>
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

				<div id="demo" class="collapse out">
					<br>

					<div class="form-group">
						<label for="root" class="col-sm-2 control-label">Root path</label>
						<div class="col-sm-10">
							<input type="text" class="form-control" id="root" name="root" placeholder="Root path" value="<?php echo defaultRoot(); ?>">
							<span class="help-block">Leave this value as is if you don't know what it is.</span>
						</div>
					</div>

					<div class="form-group">
						<label for="downloadUrl" class="col-sm-2 control-label">Download URL</label>
						<div class="col-sm-10">
							<input type="url" class="form-control" id="downloadUrl" name="downloadUrl" placeholder="Download URL" value="<?php echo defaultDownloadUrl(); ?>">
							<span class="help-block">URL to the zipball of Lighp.</span>
						</div>
					</div>

					<div class="form-group">
						<div class="col-sm-offset-2 col-sm-10">
							<div class="checkbox">
								<label>
									<input type="checkbox" name="updatePkgs"> Update packages if they are already installed
								</label>
							</div>
						</div>
					</div>
				</div>

				<div class="form-group">
					<div class="col-sm-offset-2 col-sm-10">
						<button type="submit" class="btn btn-lg btn-primary">Install</button>
						<button type="button" class="btn btn-link" data-toggle="collapse" data-target="#demo">
							Advanced options
						</button>
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

		<small class="pull-right"><a href="https://github.com/lighp/lighp/wiki/Installing_fr" target="_blank">Installation guide</a></small>

		<small><a href="http://lighp.github.io/lighp">Lighp</a> by <a href="http://emersion.fr">Emersion</a></small>
	</footer>

	<script src="//code.jquery.com/jquery-2.1.1.min.js"></script>
	<script src="//maxcdn.bootstrapcdn.com/bootstrap/3.2.0/js/bootstrap.min.js"></script>
</body>
</html>
