<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Stage_Machine {
	public const STAGE_INGESTED = 'ingested';
	public const STAGE_FILTERED = 'initial_filtered';
	public const STAGE_CONTEXT = 'context_analyzed';
	public const STAGE_DOSSIER = 'dossier_built';
	public const STAGE_DE = 'de_master_ready';
	public const STAGE_MEDIA = 'media_ready';
	public const STAGE_UK = 'uk_ready';
	public const STAGE_EN = 'en_ready';
	public const STAGE_PUBLISH = 'publish_ready';
	public const STAGE_PUBLISHED = 'published';

	public static function ordered_stages(): array {
		return [
			self::STAGE_INGESTED,
			self::STAGE_FILTERED,
			self::STAGE_CONTEXT,
			self::STAGE_DOSSIER,
			self::STAGE_DE,
			self::STAGE_MEDIA,
			self::STAGE_UK,
			self::STAGE_EN,
			self::STAGE_PUBLISH,
			self::STAGE_PUBLISHED,
		];
	}

	public static function next_stage(string $stage): ?string {
		$stages = self::ordered_stages();
		$index = array_search($stage, $stages, true);
		if ($index === false) {
			return self::STAGE_INGESTED;
		}
		return $stages[$index + 1] ?? null;
	}

	public static function processing_stages(): array {
		return [
			self::STAGE_INGESTED,
			self::STAGE_FILTERED,
			self::STAGE_CONTEXT,
			self::STAGE_DOSSIER,
			self::STAGE_DE,
			self::STAGE_MEDIA,
			self::STAGE_UK,
			self::STAGE_EN,
		];
	}
}
