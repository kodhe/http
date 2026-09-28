<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Controllers;

use Kodhe\Framework\Support\Facades\Facade;

class Controller
{
    protected static ?Facade $facade = null;
        
    /**
     * Constructor
     */
    public function __construct()
    {
        log_message('debug', "Controller Class Initialized");
        
        // Inisialisasi facade jika belum ada
        if (self::$facade === null) {
            $this->initializeFacade();
        }
        
        // Store controller reference
        self::$facade->set('__legacy_controller', $this);
        
        // Initialize loader jika ada
        if (self::$facade->has('load')) {
            self::$facade->get('load')->initialize();
        }
    }
    
    /**
     * Initialize facade dari Application/Kernel
     */
    protected function initializeFacade(): void
    {
        // Coba dapatkan dari global Application
        if (isset($GLOBALS['CI_APP'])) {
            $app = $GLOBALS['CI_APP'];
            if (method_exists($app, 'getKernel')) {
                self::$facade = $app->getKernel()->getFacade();
                return;
            }
        }
        
        // Coba dari global Kernel
        if (isset($GLOBALS['CI_KERNEL'])) {
            $kernel = $GLOBALS['CI_KERNEL'];
            if (method_exists($kernel, 'getFacade')) {
                self::$facade = $kernel->getFacade();
                return;
            }
        }
        
        // Fallback ke singleton
        self::$facade = Facade::getInstance();
    }
    
    public function __get($name)
    {
        $facade = self::getFacade();
        
        // 1. Coba dari facade service
        if ($facade->has($name)) {
            return $facade->get($name);
        }
        
        // 2. Coba dari container di facade
        if ($facade->has('di')) {
            $container = $facade->get('di');
            try {
                return $container->make($name);
            } catch (\Exception $e) {
                // Continue
            }
        }
        
        // 3. Coba akses sebagai property
        if (property_exists($facade, $name)) {
            return $facade->$name;
        }
        
        // 4. Throw error dengan informasi lebih jelas
        $availableServices = implode(', ', $facade->keys());
        throw new \RuntimeException(
            "Property {$name} not found in controller. " .
            "Available services in facade: " . ($availableServices ?: 'none')
        );
    }
    
    public function __set($name, $value)
    {
        $facade = self::getFacade();
        
        // Jangan override service yang sudah ada
        if ($facade->has($name)) {
            log_message('debug', "Cannot override service {$name} in facade");
            return false;
        }
        
        // Set sebagai property facade
        $facade->$name = $value;
        return true;
    }
    
    public static function setFacade(Facade $facade): void
    {
        self::$facade = $facade;
    }
    
    public static function getFacade(): Facade
    {
        if (self::$facade === null) {
            self::$facade = Facade::getInstance();
        }
        return self::$facade;
    }
}
