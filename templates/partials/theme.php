<?php
/**
 * Design-Overrides (Farben) als <style>-Block.
 * Kommt direkt nach dem App-Stylesheet in den <head> beider Layouts.
 */

use App\Services\Customization;

$css = (new Customization($ctx->settings))->themeCss();
if ($css !== '') {
    echo '<style>' . $css . '</style>';
}
