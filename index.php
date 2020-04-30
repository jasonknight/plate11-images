<?php
phpinfo();
preg_match("/([A-Z0-9]+)/",$_GET['id'], $m);
$id = $m[1];
$h = opendir(__DIR__);
$file = '';
$result = [
	'id' => $id,
	'files' => [],
	'primary_image' => '',
	'paths_tried' => [],
];

function tiff2jpg($file) {
    $mgck_wnd = NewMagickWand();
    MagickReadImage($mgck_wnd, $file);
    $rgb_path = join('/',[__DIR__,'converted', str_replace('.tiff','-converted.jpg',strtolower(basename($file)))]);
    @mkdir(dirname($rgb_path),0777,true);
    $img_colspc = MagickGetImageColorspace($mgck_wnd);
    if ($img_colspc == MW_CMYKColorspace) {
        MagickSetImageColorspace($mgck_wnd, MW_RGBColorspace);
    }
    MagickSetImageFormat($mgck_wnd, 'JPG' );
    MagickWriteImage($mgck_wnd, $rgb_path);
    return $rgb_path;
}
function convert_to_jpg($entry,$path) {
	$path = tiff2jpg($path);	
	return imagecreatefromjpeg($path);
}
function produce_thumb($entry,$path) {
	$maxw = 150;
	$maxh = 150;
	$spath = join('/',[dirname(__DIR__),str_replace(' ',' ',$entry['path'])]);
	list($siw,$sih,$sit) = getimagesize($spath);
	switch($sit) {
		case IMAGETYPE_JPEG:
			$gd = imagecreatefromjpeg($spath);
			break;
		case IMAGETYPE_TIFF_II:
			$gd = convert_to_jpg($entry,$spath);
			break;
		default:
			echo json_encode(['error' => 'failed to figure type']);
			break;

	}	
	$entry['meta'] = [$siw,$sih,$sit];
	if ( $gd === false ) {
		$entry['error'] = "Failed imagecreatefrom jpeg";
		return $entry;
	}
	return $entry;
}
function find_thumb($entry) {
	$thumb_path = join('/',[__DIR__,'thumbnails',basename($entry['path'])]);
	if ( ! file_exists($thumb_path) ) {
		$entry = produce_thumb($entry,$thumb_path);
	}

	$entry['thumb'] = $thumb_path;
	
	return $entry;
}
while ( false !== ($d = readdir($h)) ) {
	if ( !is_dir(__DIR__ . "/$d") || $d == '.' || $d == '..' ) {
		continue;
	}
	$path = join('/',[__DIR__,"$d/{$id}_.*"]);
	$result['paths_tried'][] = str_replace(__DIR__, '',$path);
	foreach ( glob($path) as $f ) {
		$result['primary_image'] = str_replace(dirname(__DIR__),'', $f);
		$entry = [
			'path' => str_replace(dirname(__DIR__), '', $f),
		];
		$entry = find_thumb($entry);
		$result['files'][] = $entry;

	}	
	$path = join('/',[__DIR__,"$d/*{$id}*.*"]);
	$result['paths_tried'][] = str_replace(__DIR__, '',$path);
	foreach ( glob($path) as $f ) {
		$entry = [
			'path' => str_replace(dirname(__DIR__), '', $f),
		];
		$entry = find_thumb($entry);
		$result['files'][] = $entry;
	}
}
echo json_encode($result,JSON_PRETTY_PRINT);
?>
