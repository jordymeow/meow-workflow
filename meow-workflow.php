<?php
/*
Plugin Name: Meow Workflow
Plugin URI: https://wordpress.org/plugins/meow-workflow/
Description: Visual workflow automation for WordPress. Connects AI Engine, SEO Engine, Code Engine, Social Engine and more into flows you draw on a canvas.
Version: 0.1.3
Author: Jordy Meow
Author URI: https://jordymeow.com
Text Domain: meow-workflow
Domain Path: /languages
Requires at least: 6.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( !defined( 'ABSPATH' ) ) { exit; }

define( 'MWFLOW_VERSION', '0.1.3' );
define( 'MWFLOW_PREFIX', 'mwflow' );
define( 'MWFLOW_DOMAIN', 'meow-workflow' );
define( 'MWFLOW_ENTRY', __FILE__ );
define( 'MWFLOW_PATH', dirname( __FILE__ ) );
define( 'MWFLOW_URL', plugin_dir_url( __FILE__ ) );

require_once( MWFLOW_PATH . '/classes/init.php' );
