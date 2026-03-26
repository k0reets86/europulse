<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Upgrader {
	public static function maybe_run(): void {
		EPV2_Installer::maybe_upgrade_schema();
	}
}
