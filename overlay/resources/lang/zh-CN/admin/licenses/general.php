<?php

return array(
    'about_licenses_title'      => '关于资产物品',
    'about_licenses'            => '软件资产物品用于监控软件的使用情况。它们拥有一定数量的席位，可以分配给个人使用',
    'checkin'  					=> '归还资产物品席位',
    'checkout_history'  		=> '签出历史记录',
    'checkout'  				=> '签出资产物品数量',
    'edit'  					=> '编辑资产物品',
    'filetype_info'				=> '允许的文件类型有： png, gif, jpg, jpeg, doc, docx, pdf, txt, zip, rar',
    'clone'  					=> '克隆资产物品',
    'history_for'  				=> '历史记录',
    'in_out'  					=> '进/出',
    'info'  					=> '授权信息',
    'license_seats'  			=> '授权数量',
    'seat'  					=> '席位',
    'seat_count'  				=> '席位 :count',
    'seats'  					=> '席位',
    'software_licenses'  		=> '软件资产物品',
    'user'  					=> '用户',
    'view'  					=> '查看资产物品',
    'delete_disabled'           => '此资产物品不能被删除，因为仍有席位被签出。',
    'bulk'                      =>
        [
            'checkin_all'           => [
                'button'            => '归还所有席位',
                'modal'             => '此操作将归还一个席位。| 此操作将归还这个资产物品的所有共 :checkedout_seas_count 个席位。',
                'enabled_tooltip'   => '从用户和资产中归还此资产物品的所有席位',
                'disabled_tooltip'  => '此功能已禁用，因为当前没有签出的席位',
                'disabled_tooltip_reassignable'  => '此功能已禁用，因为资产物品不可重新分配。',
                'success'           => '资产物品归还成功！| 所有资产物品都已归还成功！',
                'log_msg'           => '通过资产物品GUI中的“批量归还资产物品”进行归还',
            ],

            'checkout_all'              => [
                'button'                => '签出所有席位',
                'modal'                 => '此操作将签出一个席位给第一个可用的用户。| 此操作将签出所有共 :available _seas_count 个席位给第一个可用的用户。 如果此资产物品尚未签出给用户，并且在该用户账户上启用了“自动分配资产物品”属性，则认定该用户可以使用此席位。',
                'enabled_tooltip'   => '向所有用户签出所有（或尽可能多）的席位',
                'disabled_tooltip'  => '此功能已禁用，因为当前没有可用的席位',
                'success'           => '资产物品成功签出！ | :count 个资产物品成功签出！',
                'error_no_seats'    => '此资产物品已无剩余席位。',
                'warn_not_enough_seats'    => ':count 个用户被分配了此资产物品，但我们没有可用的资产物品席位。',
                'warn_no_avail_users'    => '没有什么要做的。没有尚未分配此资产物品的用户。',
                'log_msg'           => '在资产物品GUI中通过“批量资产物品签出”签出',


            ],
    ],

    'below_threshold' => '此资产物品仅剩:remaining_count个席位，并且最小数量为:min_amt。你可能需要考虑购买更多席位。',
    'below_threshold_short' => '该项低于最低要求数量。',
);
