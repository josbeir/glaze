<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Glaze\Config\NeonConfigEngine;

require dirname(__DIR__) . '/vendor/autoload.php';

Configure::config('default', new NeonConfigEngine());
