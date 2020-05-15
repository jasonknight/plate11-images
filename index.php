<?php
// phpinfo();
define('BASE',join('/',[__DIR__]));
define('THPATH',join('/',[BASE, 'thumbnails']));
@mkdir(THPATH,0777,true);
define('LGPATH',join('/',[BASE, 'large_images']));
@mkdir(LGPATH,0777,true);
define('CONVPATH',join('/',[BASE, 'converted']));
@mkdir(CONVPATH,0777,true);
preg_match("/([A-Z0-9]+)/",$_GET['id'], $m);
$id = $m[1];
$file = '';
$result = [
	'id' => $id,
	'files' => [],
	'primary_image' => '',
	'paths_tried' => [],
];
function error($msg) {
    echo json_encode(['error' => $msg]);
    header($_SERVER['SERVER_PROTOCOL'] . " 500 $msg",true,500);
    exit;
}
function get_thumb_path($file) {
    $rgb_path = join('/',[THPATH, str_replace('.tif','.jpg',strtolower(basename($file)))]);
    return $rgb_path;
}
function get_convert_path($file) {
    $rgb_path = join('/',[CONVPATH, str_replace('.tif','.jpg',strtolower(basename($file)))]);
    return $rgb_path;
}
function get_large_path($file) {
    $rgb_path = join('/',[LGPATH, str_replace('.tif','.jpg',strtolower(basename($file)))]);
    return $rgb_path;
}
function tiff2jpg($entry) {
    $file = $entry['path'];
    $rgb_path = get_convert_path($file);
    $cmd = "convert \"$file\" -quality 100 \"$rgb_path\"";
    exec($cmd,$output,$ret);
    $entry['converted'] = [
        'code' => $ret,
        'output' => $output
    ];
    if ( file_exists($rgb_path) ) {
        copy($rgb_path,get_large_path($rgb_path));
        unlink($rgb_path);
        $rgb_path = get_large_path($rgb_path);
    }
    $entry['path'] = $rgb_path;
    return $entry;
}
function produce_thumb($entry) {
	$maxw = 250;
	$maxh = 250;
	$spath = $entry['path'];
    if ( ! file_exists($spath) ) {
        error("$spath does not exist");
    }
	list($siw,$sih,$sit) = getimagesize($spath);
    $gd = false;
	switch($sit) {
		case IMAGETYPE_JPEG:
			$gd = imagecreatefromjpeg($spath);
			break;
		case IMAGETYPE_TIFF_II || IMAGETYPE_TIFF_MM:
			$entry = tiff2jpg($entry);
	        $spath = $entry['path'];
			$gd = imagecreatefromjpeg($spath);
			break;
		default:
            error("unknown image type $sit for $spath");
			break;

	}	
	$entry['meta'] = [$siw,$sih,$sit];
	if ( $gd === false ) {
		$entry['error'] = "Failed imagecreatefrom jpeg";
		return $entry;
	}
    // Src aspect ratio
    $sar = $siw / $sih;
    $thumb_ar = $maxw / $maxh;
    if ( $siw <= $maxw && $sih <= $maxh ) {
        $thumbw = $siw;
        $thumbh = $sih;
    } else if ( $thumb_ar > $sar ) {
        $thumbw = (int) ($maxh * $sar);
        $thumbh = $maxh;
    } else {
        $thumbw = $maxw;
        $thumbh = (int)($maxw / $sar);
    }
    $thumb_gd = imagecreatetruecolor($thumbw,$thumbh);
    imagecopyresampled(
        $thumb_gd,
        $gd,
        0,
        0,
        0,
        0,
        $thumbw,
        $thumbh,
        $siw,
        $sih
    );
    $h = imagecreatetruecolor($maxw,$maxh);
    $bg = imagecolorallocate($h,0,0,0);
    imagefill($h,0,0,$bg);
    imagecopy(
        $h,
        $thumb_gd, 
        (imagesx($h)/2) - (imagesx($thumb_gd)/2),
        (imagesy($h)/2) - (imagesy($thumb_gd)/2),
        0,
        0,
        imagesx($thumb_gd),
        imagesy($thumb_gd)
    );
    $thumb_path = get_thumb_path($entry['path']);
    $entry['thumb'] = $thumb_path;
    imagejpeg($h,$thumb_path,90);
    imagedestroy($gd);
    imagedestroy($thumb_gd);
    imagedestroy($h);
	return $entry;
}
function find_thumb($entry) {
	$entry['thumb'] = get_thumb_path($entry['path']);
	if ( ! file_exists($entry['thumb']) ) {
		$entry = produce_thumb($entry);
	}
    if ( ! file_exists($entry['thumb']) ) {
        error("failed to create {$entry['thumb']} " . print_r($entry,true));
    }
	return $entry;
}
function path2url($p) {
    $p = str_replace(dirname(BASE),'',$p);
    $p = "http://plate11.lycanthropenoir.com{$p}";
    return $p;
}
function get_entry($f) {
    $lg_path = get_large_path($f);
    if ( file_exists($lg_path) ) {
        $entry = [
            'path' => $lg_path,
        ];
        $tpath = get_thumb_path($f);
        if ( file_exists($tpath) )  {
            $entry['thumb'] = $tpath;
        }
    } else {
        $entry = [
            'path' => $f,
        ];
        $entry = find_thumb($entry);
    }
    foreach ( $entry as $k=>$v ) {
        $entry[$k] = path2url($v);
    }
    return $entry;
}
function filter_out_dups($entries,$result) {
    $new = [];
    $seen = [];
    foreach ( $entries as $entry ) {
        if ( in_array($entry['path'],$seen) )
            continue;
        if ( $entry['path'] == $result['primary_image'] )
            continue;
        $new[] = $entry;
    } 
    return $new;
}
$h = opendir(BASE);
while ( false !== ($d = readdir($h)) ) {
    if ( 
        !is_dir(BASE . "/$d") || 
        $d == '.' || 
        $d == '..' || 
        $d == basename(THPATH) || 
        $d == basename(LGPATH) || 
        $d == basename(CONVPATH) 
    ) {
		continue;
	}
	$path = join('/',[BASE,"$d/{$id}_.*"]);
	$result['paths_tried'][] = $path;
	foreach ( glob($path) as $f ) {
        $entry = get_entry($f); 
        if ( !empty($entry['path']) ) {
            $result['primary_image'] = $entry['path'];
            $result['primary_thumb'] = $entry['thumb'];
        }
		$result['files'][] = $entry;
	}	
	$path = join('/',[BASE,"$d/*{$id}*.*"]);
	$result['paths_tried'][] = $path;
	foreach ( glob($path) as $f ) {
        $entry = get_entry($f); 
        $result['files'][] = $entry;
	}
}
$result['files'] = filter_out_dups($result['files'],$result);
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
echo json_encode($result,JSON_PRETTY_PRINT);
?>
