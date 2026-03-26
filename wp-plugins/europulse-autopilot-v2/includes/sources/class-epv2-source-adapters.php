<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Source_Adapters {
	public static function rules_for(string $url): array {
		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));

		if (str_contains($host, 'hor-muenchen.de')) {
			return [
				'listing_xpath' => '//main//a[contains(@href,"veranstaltung") or contains(@href,"event")] | //article//a[@href]',
			];
		}

		if ($host === 't.me' || str_contains($host, 'telegram.me')) {
			return [
				'mode' => 'social_telegram',
			];
		}

		if (str_contains($host, 'facebook.com') || str_contains($host, 'fb.com')) {
			return [
				'listing_xpath' => '//main//a[contains(@href,"/posts/") or contains(@href,"/events/") or contains(@href,"/videos/")] | //article//a[@href]',
			];
		}

		if (str_contains($host, 'muenchen.de') && str_contains($path, '/veranstaltungen/')) {
			return [
				'listing_xpath' => '//main//a[contains(@href,"/veranstaltungen/")] | //article//a[@href]',
			];
		}

		if (str_contains($host, 'muenchen.de') && str_contains($path, '/news')) {
			return [
				'listing_xpath' => '//main//a[contains(@href,"/news/") and string-length(normalize-space()) > 18 and not(starts-with(@href,"#"))]',
			];
		}

		if (str_contains($host, 'germany4ukraine.de')) {
			return [
				'listing_xpath' => '//a[contains(@href,"SharedDocs/Meldungen/") and string-length(normalize-space()) > 18]',
			];
		}

		if (str_contains($host, 'arbeitsagentur.de')) {
			return [
				'mode' => 'single_page',
			];
		}

		if (str_contains($host, 'make-it-in-germany.com') && str_contains($path, '/arbeitsmarkt-news')) {
			return [
				'listing_xpath' => '//main//a[contains(@href,"/de/") and contains(@href,"/arbeiten-in-deutschland/") and string-length(normalize-space()) > 18]',
			];
		}

		if (str_contains($host, 'jobcenter-muenchen.de')) {
			return [
				'listing_xpath' => '//a[contains(@href,".pdf") and contains(@href,"/wp-content/uploads/")]',
			];
		}

		if (str_contains($host, 'ukrinform')) {
			return [
				'listing_xpath' => '//a[contains(@href,"/rubric-ato/") or contains(@href,"/rubric-polytics/") or contains(@href,"/rubric-economy/") or contains(@href,"/rubric-defense/") or contains(@href,"/rubric-society/")]',
			];
		}

		if (str_contains($host, 'hilfe-ua.de')) {
			return [
				'listing_xpath' => '//main//a[contains(@href,"/nachrichten/") and string-length(normalize-space()) > 18] | //article//a[@href]',
			];
		}

		if (str_contains($host, 'slavistik.lmu.de')) {
			return [
				'listing_xpath' => '//main//a[(contains(@href,"aktuelles") or contains(@href,"events") or contains(@href,"projekte")) and string-length(normalize-space()) > 18 and not(normalize-space()="Aktuelles") and not(normalize-space()="Projekte") and not(normalize-space()="Українська")]',
			];
		}

		if (str_contains($host, 'erzbistum-muenchen.de')) {
			return [
				'listing_xpath' => '//main//a[contains(@href,"ukrain")] | //article//a[@href]',
			];
		}

		return [];
	}

	public static function source_name_for_url(string $url): string {
		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		return match (true) {
			str_contains($host, 'ukrinform') => 'Ukrinform',
			str_contains($host, 'bundesregierung.de') => 'Bundesregierung',
			str_contains($host, 'bundestag.de') => 'Deutscher Bundestag',
			default => $host,
		};
	}
}
