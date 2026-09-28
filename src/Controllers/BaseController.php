<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Controllers;

class BaseController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $app = app();

        if (isset($app->load)) {
            foreach (['string'] as $helper) {
                try {
                    $app->load->helper($helper);
                } catch (\Throwable $e) {
                    // Helper optional if package path not resolved yet
                }
            }

            try {
                $app->load->library(['user_agent']);
            } catch (\Throwable $e) {
                // kodhe/agent optional
            }
        }

        try {
            if (class_exists(\Kodhe\Framework\View\ViewFactory::class)) {
                $app->set('theme', app('view'));
            }
        } catch (\Throwable $e) {
            // kodhe/view optional
        }
    }
}
