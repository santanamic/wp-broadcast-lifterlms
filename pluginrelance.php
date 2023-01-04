<?php 

$zipName = $argv[1] ?? 'my_plugin_' . uniqid();
$version = $argv[2] ?? '';

// Get zip ignore files
$pluginignore = file_get_contents(".pluginignore");
$pluginignore = explode(PHP_EOL, $pluginignore); 
$pluginignore = array_map("realpath", $pluginignore);

// Get real path for our folder
$rootPath = realpath('./');

// Initialize archive object
$zip = new ZipArchive();
$zip->open("./dist/{$zipName}_v{$version}.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addEmptyDir($zipName);

// Create recursive directory iterator
/** @var SplFileInfo[] $files */
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootPath),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($files as $name => $file)
{	
    $filePath = $file->getRealPath();
    $relativePath = substr($filePath, strlen($rootPath) + 1);
	$relativePath = str_replace('\\', '/', $relativePath);

	// Check to ignore paths
	foreach($pluginignore as $index => $path) 
	{
		if(strpos($file->getPathName(), $path) !== false) 
		{ 
			continue 2;
		}
	}
	
	// Add to zip
	if(!in_array($relativePath, ['', '.', '..'])) 
	{
		if($file->isDir()) 
		{
			$zip->addEmptyDir("$zipName/$relativePath");
		} else if(!$file->isDir()) 
		{
			$zip->addFile($filePath, "$zipName/$relativePath");
		}
	}
}

// Zip archive will be created only after closing object
$zip->close();