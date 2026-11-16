<?php

declare(strict_types=1);

// The few WordPress classes the tests build, which no WordPress is here to define.

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public string|int $code = '', public string $message = '', public mixed $data = '') {}
    }
}

if (! class_exists('WP_Term')) {
    #[AllowDynamicProperties]
    class WP_Term {}
}

if (! class_exists('WP_Post')) {
    #[AllowDynamicProperties]
    class WP_Post {}
}

if (! class_exists('WP_Taxonomy')) {
    #[AllowDynamicProperties]
    class WP_Taxonomy {}
}

if (! class_exists('WP_Block_Type')) {
    #[AllowDynamicProperties]
    class WP_Block_Type {}
}

if (! class_exists('WP_Block_Type_Registry')) {
    class WP_Block_Type_Registry
    {
        /** @var array<string, WP_Block_Type> */
        public array $types = [];

        public static function get_instance(): self
        {
            static $instance = null;

            return $instance ??= new self;
        }

        public function get_registered(string $name): ?WP_Block_Type
        {
            return $this->types[$name] ?? null;
        }
    }
}
