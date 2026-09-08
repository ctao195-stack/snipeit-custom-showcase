<?php

return [
    'serial_width' => 5,

    'ownership_types' => [
        'rental' => [
            'label' => '租赁',
            'base_prefix' => env('ASSET_TAG_RENTAL_PREFIX', 'RENT-GZ-B'),
        ],
        'owned' => [
            'label' => '自购',
            'base_prefix' => env('ASSET_TAG_OWNED_PREFIX', 'OWN-GZ-B'),
        ],
    ],

    'device_types' => [
        'desktop' => [
            'label' => '主机',
            'code' => '001',
        ],
        'monitor' => [
            'label' => '显示器',
            'code' => '002',
        ],
        'laptop' => [
            'label' => '笔记本',
            'code' => '003',
        ],
    ],
];
