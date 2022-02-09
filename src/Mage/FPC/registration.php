<?php
\Magento\Framework\Component\ComponentRegistrar::register(
	\Magento\Framework\Component\ComponentRegistrar::MODULE,
	'Mage_FPC',
	__DIR__
);

if (!class_exists('FPC')) {
    require ("FPC.php");
}
