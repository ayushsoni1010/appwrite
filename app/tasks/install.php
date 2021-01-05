<?php

global $cli;

use Appwrite\Docker\Compose;
use Appwrite\Docker\Env;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Validator\Mock;
use Utopia\View;

$cli
    ->task('install')
    ->desc('Install Appwrite')
    ->param('version', APP_VERSION_STABLE, new Mock(), 'Appwrite version', true)
    ->action(function ($version) {
        /**
         * 1. Start - DONE
         * 2. Check for older setup and get older version - DONE
         *  2.1 If older version is equal or bigger(?) than current version, **stop setup**
         *  2.2. Get ENV vars - DONE
         *   2.2.1 Fetch from older docker-compose.yml file
         *   2.2.2 Fetch from older .env file (manually parse)
         *  2.3 Use old ENV vars as default values
         *  2.4 Ask for all required vars not given as CLI args and if in interactive mode
         *      Otherwise, just use default vars. - DONE
         * 3. Ask user to backup important volumes, env vars, and SQL tables
         *      In th future we can try and automate this for smaller/medium size setups 
         * 4. Drop new docker-compose.yml setup (located inside the container, no network dependencies with appwrite.io) - DONE
         * 5. Run docker-compose up -d - DONE
         * 6. Run data migration
         */
        $vars = Config::getParam('variables');
        $path = '/usr/src/code/appwrite';
        $defaultHTTPPort = '80';
        $defaultHTTPSPort = '443';

        Console::success('Starting Appwrite installation...');

        // Create directory with write permissions
        if (null !== $path && !\file_exists(\dirname($path))) {
            if (!@\mkdir(\dirname($path), 0755, true)) {
                Console::error('Can\'t create directory '.\dirname($path));
                exit(1);
            }
        }

        $data = @file_get_contents($path.'/docker-compose.yml');

        if($data !== false) {
            $compose = new Compose($data);
            $appwrite = $compose->getService('appwrite');
            $oldVersion = ($appwrite) ? $appwrite->getImageVersion() : null;
            $ports = $compose->getService('traefik')->getPorts();

            if($oldVersion) {
                foreach($compose->getServices() as $service) { // Fetch all env vars from previous compose file
                    if(!$service) {
                        continue;
                    }

                    $env = $service->getEnvironment()->list();

                    foreach ($env as $key => $value) {
                        foreach($vars as &$var) {
                            if($var['name'] === $key) {
                                $var['default'] = $value;
                            }
                        }
                    }
                }

                $data = @file_get_contents($path.'/.env');

                if($data !== false) { // Fetch all env vars from previous .env file
                    $env = new Env($data);

                    foreach ($env->list() as $key => $value) {
                        foreach($vars as &$var) {
                            if($var['name'] === $key) {
                                $var['default'] = $value;
                            }
                        }
                    }
                }

                foreach ($ports as $key => $value) {
                    if($value === $defaultHTTPPort) {
                        $defaultHTTPPort = $key;
                    }

                    if($value === $defaultHTTPSPort) {
                        $defaultHTTPSPort = $key;
                    }
                }
            }
        }

        $httpPort = Console::confirm('Choose your server HTTP port: (default: '.$defaultHTTPPort.')');
        $httpPort = ($httpPort) ? $httpPort : $defaultHTTPPort;

        $httpsPort = Console::confirm('Choose your server HTTPS port: (default: '.$defaultHTTPSPort.')');
        $httpsPort = ($httpsPort) ? $httpsPort : $defaultHTTPSPort;
    
        $input = [];

        foreach($vars as $key => $var) {
            if(!$var['required']) {
                $input[$var['name']] = $var['default'];
                continue;
            }

            $input[$var['name']] = Console::confirm($var['question'].' (default: \''.$var['default'].'\')');

            if(empty($input[$key])) {
                $input[$var['name']] = $var['default'];
            }
        }

        $templateForCompose = new View(__DIR__.'/../views/install/compose.phtml');
        $templateForEnv = new View(__DIR__.'/../views/install/env.phtml');

        $templateForCompose
            ->setParam('httpPort', $httpPort)
            ->setParam('httpsPort', $httpsPort)
            ->setParam('version', $version)
        ;
        
        $templateForEnv
            ->setParam('vars', $input)
        ;

        if(!file_put_contents($path.'/docker-compose.yml', $templateForCompose->render(false))) {
            Console::error('Failed to save Docker Compose file');
            exit(1);
        }

        if(!file_put_contents($path.'/.env', $templateForEnv->render(false))) {
            Console::error('Failed to save environment variables file');
            exit(1);
        }

        $stdout = '';
        $stderr = '';

        Console::log("Running \"docker-compose -f {$path}/docker-compose.yml up -d --remove-orphans --renew-anon-volumes\"");

        $exit = Console::execute("docker-compose -f {$path}/docker-compose.yml up -d --remove-orphans --renew-anon-volumes", '', $stdout, $stderr);

        if ($exit !== 0) {
            Console::error("Failed to install Appwrite dockers");
            Console::error($stderr);
            exit($exit);
        } else {
            Console::success("Appwrite installed successfully");
        }
    });