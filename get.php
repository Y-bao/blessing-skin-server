<?php
/**
 * @Author: prpr
 * @Date:   2016-02-02 20:56:42
 * @Last Modified by:   printempw
 * @Last Modified time: 2016-03-13 09:27:24
 *
 * All textures requests of legacy link will be handle here.
 */

$dir = dirname(__FILE__);
require "$dir/includes/autoload.inc.php";

if (isset($_GET['type']) && isset($_GET['uname'])) {
    $user = new User($_GET['uname']);
    if (!$user->is_registered) {
        header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
        Utils::raise(1, 'Non-existent user.');
    }
    // Cache friendly
    $if_modified_since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ?
                                strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : null;
    // Image bin data
    if ($_GET['type'] == "skin" || $_GET['type'] == "cape") {
        $model_preferrnce = ($user->getPreference() == "default") ? "steve" : "alex";
        $model = (isset($_GET['model']) && $_GET['model'] == "") ? $model_preferrnce : $_GET['model'];
        if ($if_modified_since >= $user->getLastModified()) {
            header('HTTP/1.0 304 Not Modified');
        } else {
            header('Content-Type: image/png');
            if ($_GET['type'] == "cape") {
                echo $user->getBinaryTexture('cape');
            } else {
                echo $user->getBinaryTexture($model);
            }
        }
    // JSON profile
    } else if ($_GET['type'] == "json") {
        if (isset($_GET['api'])) {
            echo $user->getJsonProfile(($_GET['api'] == 'csl') ? 0 : 1);
        } else {
            echo $user->getJsonProfile(API_TYPE);
        }
    } else {
        Utils::raise(1, 'Illegal parameters.');
    }
} else {
    Utils::raise(1, 'Illegal parameters.');
}
