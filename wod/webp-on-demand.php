<?php
// https://www.askapache.com/htaccess/crazy-advanced-mod_rewrite-tutorial/#Decoding_Mod_Rewrite_Variables

ini_set('display_errors', 1);
error_reporting(E_ALL);

//echo 'display errors:' . ini_get('display_errors');
//exit;

//require 'webp-on-demand-1.inc';
//require '../vendor/autoload.php';

//print_r($_GET); exit;

use \WebPConvert\WebPConvert;
use \WebPConvert\ServeExistingOrHandOver;

function exitWithError($msg) {
    header('X-WebP-Express-Error: ' . $msg, true);
    echo $msg;
    exit;
}

if (preg_match('#webp-on-demand.php#', $_SERVER['REQUEST_URI'])) {
    exitWithError('Direct access is not allowed');
    exit;
}

function loadConfig($configFilename) {
    if (!file_exists($configFilename)) {
        header('X-WebP-Express-Error: Configuration file not found!', true);
        echo 'Configuration file not found!';
        //WebPConvert::convertAndServe($source, $destination, []);
        exit;
    }

    // TODO: Handle read error / json error
    $handle = @fopen($configFilename, "r");
    $json = fread($handle, filesize($configFilename));
    fclose($handle);
    return json_decode($json, true);
}

function getSource() {
    global $options;
    global $docRoot;

    if ($options['method-for-passing-source'] == 'querystring-full-path') {
        if (isset($_GET['xsource'])) {
            return substr($_GET['xsource'], 1);         // No url decoding needed as $_GET is already decoded
        } elseif (isset($_GET['source'])) {
            return $_GET['source'];
        } else {
            exitWithError('Method for passing filename was set to querystring (full path), but neither "source" or "xsource" params are in the querystring)');
        }
    }

    if ($options['method-for-passing-source'] == 'querystring-relative-path') {
        $srcRel = '';
        if (isset($_GET['xsource-rel'])) {
            $srcRel = substr($_GET['xsource-rel'], 1);
        } elseif (isset($_GET['source-rel'])) {
            $srcRel = $_GET['source-rel'];
        } else {
            exitWithError('Method for passing filename was set to querystring (full path), but neither "source-rel" or "xsource-rel" params are in the querystring)');
        }

        if (isset($_GET['source-rel-filter'])) {
            if ($_GET['source-rel-filter'] == 'discard-parts-before-wp-content') {
                $parts = explode('/', $srcRel);
                $wp_content = isset($_GET['wp-content']) ? $_GET['wp-content'] : 'wp-content';

                if (in_array($wp_content, $parts)) {
                    foreach($parts as $index => $part) {
                        if($part !== $wp_content) {
                            unset($parts[$index]);
                        } else {
                            break;
                        }
                    }
                    $srcRel = implode('/', $parts);
                }
            }
        }

        return $docRoot . '/' . $srcRel;
    }


    //echo '<pre>' . print_r($_SERVER, true) . '</pre>'; exit;

    // First check if it is in an environment variable - thats the safest way
    foreach ($_SERVER as $key => $item) {
        if (substr($key, -14) == 'REDIRECT_REQFN') {
            return $item;
        }
    }

    if ($options['method-for-passing-source'] == 'request-header') {
        if (isset($_SERVER['HTTP_REQFN'])) {
            return $_SERVER['HTTP_REQFN'];
        }
    }

    // Last resort is to use $_SERVER['REQUEST_URI'], well knowing that it does not give the
    // correct result in all setups (ie "folder method 1")
    $requestUriNoQS = explode('?', $_SERVER['REQUEST_URI'])[0];
    //$docRoot = rtrim(realpath($_SERVER["DOCUMENT_ROOT"]), '/');
    $source = $docRoot . urldecode($requestUriNoQS);
    if (file_exists($source)) {
        return $source;
    }

    exitWithError('Could not locate source file. Try another method (in the Redirection Rules section in WebP settings)');

    /*
    if (!$allowInHeader) {
        echo '<br>Have you tried allowing source to be passed as a request header?';
    }
    if (!$allowInQS) {
        echo '<br>Have you tried allowing source to be passed in querystring?';
    }*/
    exit;
}

$docRoot = rtrim(realpath($_SERVER["DOCUMENT_ROOT"]), '/');
$wpContentDirRel = (isset($_GET['wp-content']) ? $_GET['wp-content'] : 'wp-content');
$webExpressContentDirRel = $wpContentDirRel . '/webp-express';
$webExpressContentDirAbs = $docRoot . '/' . $webExpressContentDirRel;
$configFilename = $webExpressContentDirAbs . '/config/wod-options.json';

$options = loadConfig($configFilename);

$source = getSource();
//$source = getSource(false, false);

//echo $source; exit;

if (!file_exists($source)) {
    header('X-WebP-Express-Error: Source file not found!', true);
    echo 'Source file not found!';
    exit;
}

// Determine if we should store mingled or not
function storeMingled() {
    global $options;
    global $source;
    global $docRoot;

    $destinationOptionSetToMingled = (isset($options['destination-folder']) && ($options['destination-folder'] == 'mingled'));
    if (!$destinationOptionSetToMingled) {
        return false;
    }

    // Option is set for mingled.
    // But we will only store "mingled", for images in upload folder

    if (!isset($options['paths']['uploadDirRel'])) {
        // Hm, we dont know the upload dir, as the configuration hasn't been regenerated.
        // This should not happen because configuration file is saved upon migration to 0.11
        // So we can do this wild guess:
        return preg_match('/\\/uploads\\//', $source);
    }

    $uploadDirAbs = $docRoot . '/' . $options['paths']['uploadDirRel'];
    if (strpos($source, $uploadDirAbs) === 0) {
        // We are in upload folder
        return true;
    }
    return false;
}


// Calculate $destination
// ----------------------

if (storeMingled($options)) {
    if (isset($options['destination-extension']) && ($options['destination-extension'] == 'append')) {
        $destination = $source . '.webp';
    } else {
        $destination = preg_replace('/\\.(jpe?g|png)$/', '', $source) . '.webp';
    }
} else {

    $imageRoot = $webExpressContentDirAbs . '/webp-images';

    // Check if source is residing inside document root.
    // (it is, if path starts with document root + '/')
    if (substr($source, 0, strlen($docRoot) + 1) === $docRoot . '/') {

        // We store relative to document root.
        // "Eat" the left part off the source parameter which contains the document root.
        // and also eat the slash (+1)
        $sourceRel = substr($source, strlen($docRoot) + 1);
        $destination = $imageRoot . '/doc-root/' . $sourceRel . '.webp';
    } else {
        // Source file is residing outside document root.
        // we must add complete path to structure
        $destination = $imageRoot . '/abs' . $source . '.webp';
    }
}





//echo $destination; exit;


//echo '<pre>' . print_r($options, true) . '</pre>';
//exit;

foreach ($options['converters'] as &$converter) {
    if (isset($converter['converter'])) {
        $converterId = $converter['converter'];
    } else {
        $converterId = $converter;
    }
    if ($converterId == 'cwebp') {
        $converter['options']['rel-path-to-precompiled-binaries'] = '../src/Converters/Binaries';
    }
}

if ($options['forward-query-string']) {
    if (isset($_GET['debug'])) {
        $options['show-report'] = true;
    }
    if (isset($_GET['reconvert'])) {
        $options['reconvert'] = true;
    }
}

function aboutToServeImageCallBack($servingWhat, $whyServingThis, $obj) {
    return false;   // do not serve!
}

$options['require-for-conversion'] = 'webp-on-demand-2.inc';
//$options['require-for-conversion'] = '../../../autoload.php';

include_once '../vendor/rosell-dk/webp-convert/build/webp-on-demand-1.inc';

if (isset($options['success-response']) && ($options['success-response'] == 'original')) {

    /*
    We want to convert, but serve the original. This is a bit unusual and requires a little tweaking

    First, we use the "decideWhatToServe" method of WebPConvert to find out if we should convert or not

    If result is "destination", it means there is a useful webp image at the destination (no reason to convert)
    If result is "source", it means that source is lighter than existing webp image (no reason to convert)
    If result is "fresh-conversion", it means we should convert
    */
    $server = new \WebPConvert\Serve\ServeExistingOrHandOver($source, $destination, $options);
    $server->decideWhatToServe();

    if ($server->whatToServe == 'fresh-conversion') {
        // Conversion time.
        // To prevent the serving, we use the callback
        $options['aboutToServeImageCallBack'] = 'aboutToServeImageCallBack';
        WebPConvert::convertAndServe($source, $destination, $options);

        // remove the callback, we are going for another round
        unset($options['aboutToServeImageCallBack']);
        unset($options['require-for-conversion']);
    }

    // Serve time
    $options['serve-original'] = true;      // Serve original
    $options['add-vary-header'] = false;

    WebPConvert::convertAndServe($source, $destination, $options);

} else {
    WebPConvert::convertAndServe($source, $destination, $options);
}

//echo "<pre>source: $source \ndestination: $destination \n\noptions:" . print_r($options, true) . '</pre>'; exit;
