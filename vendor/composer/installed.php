<?php return array(
    'root' => array(
        'name' => 'haeflimi/shoutbox',
        'pretty_version' => 'dev-master',
        'version' => 'dev-master',
        'reference' => '6394475e47d7935168e5279c5944d12ef3d46add',
        'type' => 'concrete5-package',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'composer/installers' => array(
            'pretty_version' => 'v1.12.0',
            'version' => '1.12.0.0',
            'reference' => 'd20a64ed3c94748397ff5973488761b22f6d3f19',
            'type' => 'composer-plugin',
            'install_path' => __DIR__ . '/./installers',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'haeflimi/shoutbox' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => '6394475e47d7935168e5279c5944d12ef3d46add',
            'type' => 'concrete5-package',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'roundcube/plugin-installer' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
        'shama/baton' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
    ),
);
