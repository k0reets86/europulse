<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Categorizer {
	public static function detect(string $title, string $content, string $source_bias = ''): string {
		$text = mb_strtolower($title . ' ' . wp_strip_all_tags($content));
		$title_text = mb_strtolower(wp_strip_all_tags($title));
		$lead_text = mb_strtolower(wp_strip_all_tags($content));
		$map = [
			'deutschland' => ['deutschland', 'germany', 'deutsch', 'bundesweit', 'немеччин', 'фрн', 'wetter', 'weather', 'steinmeier', 'bundespräsident'],
			'leben-in-deutschland' => [
				'bamf', 'bürgergeld', 'jobcenter', 'integration', 'refugee', 'aufenthalt', 'aufenthaltstitel',
				'aufenthaltsrecht', 'anerkennung', 'einbürgerung', 'ausländerbehörde', 'wohngeld', 'kindergeld',
				'arbeitsagentur', 'germany4ukraine', 'make-it-in-germany', 'make it in germany', 'schutzstatus',
				'fiktionsbescheinigung', 'integrationskurs', 'sprachkurs', 'arbeitsmarkt', 'benefits', 'labour market',
				'citizen money', 'migrant', 'migration office', 'мiгрант', 'біжен', 'дозвіл на проживання', 'німеччин'
			],
			'ukraine' => ['ukraine', 'ukrain', 'kyiv', 'київ', 'україн', 'zelensky', 'selensky', 'russland', 'russia', 'kreml', 'moskau', 'львів', 'львов', 'lviv'],
			'politik' => ['bundestag', 'wahl', 'polit', 'parliament', 'regierung', 'коаліц', 'trump', 'nato', 'hormus', 'sanktion', 'coalition', 'koalition', 'cdu', 'spd', 'gruene', 'greens', 'demokratie', 'steinmeier', 'bundespräsident', 'bundesregierung', 'gesetzentwurf', 'digitalausschuss', 'transparenzgesetz', 'politische werbung', 'politische-werbung', 'eu-verordnung'],
			'wirtschaft' => ['wirtschaft', 'inflation', 'econom', 'gdp', 'market', 'компан', 'інфляц', 'kapitalmarkt', 'spritpreis', 'kraftstoffpreis', 'dax', 'aktie', 'aktien', 'investor', 'investoren', 'ölpreis', 'oil price', 'energy price', 'energiepreis', 'paypal', 'gaspreis', 'gaspreise', 'tarif', 'tarife', 'börse', 'finanz', 'sondervermögen', 'infrastrukturfonds', 'wechsel des anbieter', 'anbieterwechsel'],
			'world' => ['welt', 'world', 'global', 'amerika', 'usa', 'united states', 'washington', 'china', 'beijing', 'taiwan', 'india', 'pakistan', 'asia', 'nahost', 'middle east', 'gaza', 'libanon', 'lebanon', 'iran', 'israel', 'syrien', 'syria', 'afrika', 'africa', 'latin america', 'lateinamerika', 'brisbane', 'australia', 'australien', 'palace', 'royal', 'monarchy', 'king', 'queen', 'prince', 'princess', 'illinois', 'britain', 'british', 'london'],
			'community' => [
				'community', 'verein', 'initiative', 'event', 'зустріч', 'поді', 'diaspora', 'ukrainische gemeinde',
				'ukrainian community', 'netzwerktreffen', 'ehrenamt', 'freiwillig', 'benefiz', 'solidarity',
				'ukrainische initiative', 'begegnung', 'nachbarschaft', 'bahnhofsmission', 'мюнх', 'громад', 'hilfsangebot', 'sozialarbeit',
				'workshop', 'beratung', 'sprechstunde', 'treffpunkt', 'community center', 'vernetzung', 'vereinsleben', 'анонс'
			],
			'münchen' => ['münchen', 'munich', 'мюнх'],
			'bayern' => ['bayern', 'bavaria', 'бавар'],
			'europa' => ['europa', 'europe', 'europarl', 'eu-', 'європ'],
			'kultur' => ['kultur', 'culture', 'концерт', 'festival', 'museum', 'theater', 'theatre', 'theaterpreis', 'preis des bundes', 'bundes-theaterpreis', 'kunst', 'ausstellung', 'premiere', 'bühne', 'haus erhält förderung'],
			'sport' => ['sport', 'fußball', 'football', 'bundesliga', 'теніс', 'fc bayern', 'schiedsrichter', 'leverkusen', 'hoeneß', 'matthäus', 'uli hoeneß', 'lothar matthäus'],
		];

		$scores = [];
		foreach ($map as $slug => $keywords) {
			$scores[$slug] = 0;
			foreach ($keywords as $keyword) {
				if (mb_strpos($text, $keyword) !== false) {
					$scores[$slug]++;
				}
			}
		}

		foreach (array_values(array_filter(array_map('trim', explode(',', sanitize_text_field($source_bias))))) as $bias) {
			if (! isset($scores[$bias]) || ! self::source_bias_should_apply($bias, $text)) {
				continue;
			}
			$scores[$bias] += in_array($bias, ['sport', 'kultur', 'community', 'wirtschaft', 'leben-in-deutschland'], true) ? 3 : 1;
		}

		self::apply_semantic_focus($scores, $title_text, $lead_text);

		if (preg_match('/\b(fc bayern|bundesliga|dfb|uefa|champions league|europa league|conference league|schiri|schiedsrichter|trainer|transfer|tor|halbfinale|premier league|premiere league|lothar matthäus|matthäus|hoeneß|handball|basketball|nhl|nba|euroleague)\b/u', $text)) {
			$scores['sport'] = ($scores['sport'] ?? 0) + 6;
			$scores['politik'] = max(0, (int) ($scores['politik'] ?? 0) - 2);
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 1);
		}
		if (preg_match('/\b(paralymp|paralympics|paralympische spiele|parasport|team d bei den paralympics|deutsches team bei den paralympics)\b/u', $text)) {
			$scores['sport'] = ($scores['sport'] ?? 0) + 10;
			$scores['leben-in-deutschland'] = max(0, (int) ($scores['leben-in-deutschland'] ?? 0) - 5);
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 2;
		}
		if (preg_match('/\b(karlsruher sc|greuther fürth|ksc|spieltag|live-stream und tv|live-stream|live im tv|torwart|keeper|bergamo)\b/u', $text)) {
			$scores['sport'] = ($scores['sport'] ?? 0) + 9;
			$scores['bayern'] = max(0, (int) ($scores['bayern'] ?? 0) - 2);
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 2);
			$scores['kultur'] = max(0, (int) ($scores['kultur'] ?? 0) - 3);
		}
		if (preg_match('/\b(theater|festival|konzert|ausstellung|museum|kino|premiere|preis|roman|film|musik|oper|schauspiel)\b/u', $text)) {
			$scores['kultur'] = ($scores['kultur'] ?? 0) + 5;
			$scores['politik'] = max(0, (int) ($scores['politik'] ?? 0) - 2);
		}
		if (preg_match('/\b(museumsinsel|alte nationalgalerie|hamburger bahnhof|kunstgewerbemuseum|nationalgalerie|impressionism|fashion becomes art)\b/u', $text)) {
			$scores['kultur'] = ($scores['kultur'] ?? 0) + 9;
			$scores['politik'] = max(0, (int) ($scores['politik'] ?? 0) - 3);
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 2);
		}
		if (preg_match('/\b(ard|zdf|rtl|prosieben|fernsehen|tv-show|quizshow|quiz show|streaming|mediathek|sendung|wer weiß denn sowas|wer weiss denn sowas)\b/u', $text)) {
			$scores['kultur'] = ($scores['kultur'] ?? 0) + 8;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 2);
			$scores['politik'] = max(0, (int) ($scores['politik'] ?? 0) - 3);
		}
		if (preg_match('/\b(the voice|schölermann|thore schölermann|moderator|moderation|unterhaltungsshow|casting show|staffel|jury)\b/u', $text)) {
			$scores['kultur'] = ($scores['kultur'] ?? 0) + 12;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 4);
			$scores['politik'] = max(0, (int) ($scores['politik'] ?? 0) - 4);
		}
		if (preg_match('/\b(theaterpreis|bundes-theaterpreis|förderung für theater|vier häuser|bühnenförderung)\b/u', $text)) {
			$scores['kultur'] = ($scores['kultur'] ?? 0) + 8;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 2);
		}
		if (preg_match('/\b(wetter|weather|temperaturen|wind|wolken|regen|sunshine|sonne|forecast)\b/u', $text)) {
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 7;
			$scores['wirtschaft'] = max(0, (int) ($scores['wirtschaft'] ?? 0) - 2);
			$scores['kultur'] = max(0, (int) ($scores['kultur'] ?? 0) - 3);
		}
		if (preg_match('/\b(pflege|pflegekraft|altenpflege|pflegeeinrichtung|pflegedienst|krankenhaus|pflegekommission|mindestlohn in der pflege)\b/u', $text)) {
			$scores['leben-in-deutschland'] = ($scores['leben-in-deutschland'] ?? 0) + 8;
			$scores['kultur'] = max(0, (int) ($scores['kultur'] ?? 0) - 4);
			$scores['wirtschaft'] = max(0, (int) ($scores['wirtschaft'] ?? 0) - 1);
		}
		if (preg_match('/\b(kriminalstatistik|polizeiliche kriminalstatistik|polizei|sicherheitsbilanz|innenminister|herrmann stellt|kriminalität)\b/u', $text)) {
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 7;
			$scores['bayern'] = ($scores['bayern'] ?? 0) + 5;
			$scores['wirtschaft'] = max(0, (int) ($scores['wirtschaft'] ?? 0) - 4);
			$scores['kultur'] = max(0, (int) ($scores['kultur'] ?? 0) - 3);
		}
		if (preg_match('/\b(warnstreik|öpnv|nahverkehr|busse und bahnen|verdi|streik in bonn|bonner nahverkehr|bahnstreik)\b/u', $text)) {
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 14;
			$scores['ukraine'] = max(0, (int) ($scores['ukraine'] ?? 0) - 10);
			$scores['sport'] = max(0, (int) ($scores['sport'] ?? 0) - 2);
		}
		if (preg_match('/\b(münchen|munich|allianz arena|allianz-arena|mvg|spessart|mittelsinn|bürgermeister|bürgermeisterwahl|stichwahl)\b/u', $text)) {
			$scores['bayern'] = ($scores['bayern'] ?? 0) + 8;
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 1;
			$scores['world'] = max(0, (int) ($scores['world'] ?? 0) - 3);
		}
		if (preg_match('/\b(münchen|munich|allianz arena|allianz-arena)\b/u', $text) && preg_match('/\b(warnstreik|öpnv|nahverkehr|busse und bahnen|pendler|champions league)\b/u', $text)) {
			$scores['bayern'] = ($scores['bayern'] ?? 0) + 10;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 4);
		}
		if (preg_match('/\b(ukraine-krieg|ukraine krieg|krieg in der ukraine|krieg gegen die ukraine|russland greift|angriff auf kiew|angriff auf kyiv|kiew|kyiv|luftangriff|raketenangriff)\b/u', $text)) {
			$scores['ukraine'] = ($scores['ukraine'] ?? 0) + 10;
			$scores['sport'] = max(0, (int) ($scores['sport'] ?? 0) - 6);
			$scores['world'] = max(0, (int) ($scores['world'] ?? 0) - 2);
		}
		if (preg_match('/\b(львів|львов|lviv|львівщин|львовщин)\b/u', $text)) {
			$scores['ukraine'] = ($scores['ukraine'] ?? 0) + 9;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 6);
			$scores['bayern'] = max(0, (int) ($scores['bayern'] ?? 0) - 4);
		}
		if (preg_match('/\b(bundeslaufbahnverordnung|öffentlichen dienst|oeffentlichen dienst|dienstrecht|bundesdienst|laufbahnverordnung|beamtinnen|beamte)\b/u', $text)) {
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 9;
			$scores['leben-in-deutschland'] = ($scores['leben-in-deutschland'] ?? 0) + 4;
			$scores['world'] = max(0, (int) ($scores['world'] ?? 0) - 8);
		}
		if (preg_match('/\b(aserbaidschan|azerbaijan|alijew|aliyev|kaukasus|caucasus|südkaukasus|south caucasus)\b/u', $text)) {
			$scores['world'] = ($scores['world'] ?? 0) + 8;
			$scores['politik'] = ($scores['politik'] ?? 0) + 3;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 3);
			$scores['bayern'] = max(0, (int) ($scores['bayern'] ?? 0) - 2);
		}
		if (preg_match('/\b(ukrsalisnyzja|ukrzaliznytsia|uz\b|zugfahrkarte|zugticket|tickets? für züge|grenzbahnhof|bahn aus der ukraine|reise nach europa|fahrt in die eu)\b/u', $text)) {
			$scores['ukraine'] = ($scores['ukraine'] ?? 0) + 6;
			$scores['europa'] = ($scores['europa'] ?? 0) + 4;
			$scores['leben-in-deutschland'] = ($scores['leben-in-deutschland'] ?? 0) + 2;
			$scores['sport'] = max(0, (int) ($scores['sport'] ?? 0) - 6);
		}
		if (preg_match('/\b(steinmeier|bundespräsident|demokratie|verfassung|verfassungs|freiheit)\b/u', $text)) {
			$scores['politik'] = ($scores['politik'] ?? 0) + 7;
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 4;
			$scores['kultur'] = max(0, (int) ($scores['kultur'] ?? 0) - 4);
		}
		if (preg_match('/\b(merz|friedrich merz|regierungserklärung|regierungserklaerung|kanzler|eu-selbstbewusstsein|afd|europäische union|europaeische union)\b/u', $text)) {
			$scores['politik'] = ($scores['politik'] ?? 0) + 10;
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 1;
			$scores['bayern'] = max(0, (int) ($scores['bayern'] ?? 0) - 4);
		}
		if (preg_match('/\b(steinmeier|bundespräsident)\b/u', $text) && preg_match('/\b(demokratie|freiheit|shoah|gastbeitrag|traditionen)\b/u', $text)) {
			$scores['politik'] = ($scores['politik'] ?? 0) + 6;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 2);
		}
		if (preg_match('/\b(sondervermögen|infrastrukturfonds|infrastruktur-zukunftsgesetz|zweckentfremdung|haushalt|milliarden)\b/u', $text)) {
			$scores['wirtschaft'] = ($scores['wirtschaft'] ?? 0) + 7;
			$scores['politik'] = max(0, (int) ($scores['politik'] ?? 0) - 1);
			$scores['community'] = max(0, (int) ($scores['community'] ?? 0) - 2);
		}
		if (preg_match('/\b(progressive kommunale schuldenbremse|kommunale schuldenbremse|kommunalen schuldenbremse|kommunale finanzlage|deutsche kommunen)\b/u', $text)) {
			$scores['wirtschaft'] = ($scores['wirtschaft'] ?? 0) + 8;
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 2;
			$scores['politik'] = max(0, (int) ($scores['politik'] ?? 0) - 1);
		}
		if (preg_match('/\b(kraftstoffpreis|kraftstoffpreise|spritpreis|spritpreise|benzinpreis|benzinpreise|altersvorsorgereform|altersvorsorge|rentenreform|entlastung|entlastungen|preise für energie|energiepreis-?entlastungen)\b/u', $text)) {
			$scores['wirtschaft'] = ($scores['wirtschaft'] ?? 0) + 8;
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 2;
			$scores['world'] = max(0, (int) ($scores['world'] ?? 0) - 8);
		}
		if (preg_match('/\b(paypal|google wallet|wallet|nfc-zahlung|smartphone|bezahlen am handy|zahlungen per smartphone)\b/u', $text)) {
			$scores['wirtschaft'] = ($scores['wirtschaft'] ?? 0) + 9;
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 1;
			$scores['world'] = max(0, (int) ($scores['world'] ?? 0) - 6);
		}
		if (preg_match('/\b(kritis-dachgesetz|kritische infrastrukturen|kritischer infrastrukturen|mindeststandards für den schutz kritischer infrastrukturen)\b/u', $text)) {
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 7;
			$scores['wirtschaft'] = ($scores['wirtschaft'] ?? 0) + 4;
			$scores['world'] = max(0, (int) ($scores['world'] ?? 0) - 5);
		}
		if (preg_match('/\b(bahnhofsmission|nachfrage steigt|hilfsangebot|freiwillige helfer|hilfe am bahnhof)\b/u', $text)) {
			$scores['community'] = ($scores['community'] ?? 0) + 9;
			$scores['leben-in-deutschland'] = ($scores['leben-in-deutschland'] ?? 0) + 3;
			$scores['world'] = max(0, (int) ($scores['world'] ?? 0) - 8);
		}
		if (preg_match('/\b(slapp|einschüchterungsklagen|schutz vor slapp-klagen|recht und verbraucherschutz|eu-richtlinie|missbräuchlichen klagen|öffentliche beteiligung)\b/u', $text)) {
			$scores['politik'] = ($scores['politik'] ?? 0) + 8;
			$scores['europa'] = ($scores['europa'] ?? 0) + 3;
			$scores['wirtschaft'] = max(0, (int) ($scores['wirtschaft'] ?? 0) - 3);
		}
		if (preg_match('/\b(bonn|general-anzeiger bonn|mehreren bundesländern|verdi ruft|streiks im nahverkehr)\b/u', $text)) {
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 7;
			$scores['bayern'] = max(0, (int) ($scores['bayern'] ?? 0) - 3);
		}
		if (preg_match('/\b(denkmal|mahnung und erinnerung|opfer der kommunistischen diktatur|gedenken|wettbewerb)\b/u', $text)) {
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 5;
			$scores['politik'] = ($scores['politik'] ?? 0) + 3;
			$scores['kultur'] = max(0, (int) ($scores['kultur'] ?? 0) - 2);
		}
		if (preg_match('/\b(verein|initiative|netzwerk|treffen|beratung|sprachkurs|community|diaspora|bahnhofsmission|hilfsangebot|veranstaltung)\b/u', $text)) {
			$scores['community'] = ($scores['community'] ?? 0) + 4;
		}
		if (preg_match('/\b(workshop|sprechstunde|anmeldung|community center|ehrenamtliche|ukrainische gemeinde|diaspora event|vereinstreffen|beratungsangebot)\b/u', $text)) {
			$scores['community'] = ($scores['community'] ?? 0) + 7;
			$scores['politik'] = max(0, (int) ($scores['politik'] ?? 0) - 2);
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 1);
		}
		if (preg_match('/\b(aufenthalt|anmeldung|kindergeld|wohngeld|versicherung|steuerklasse|ukrainische geflüchtete|jobcenter|arbeitsagentur|ausländerbehörde)\b/u', $text)) {
			$scores['leben-in-deutschland'] = ($scores['leben-in-deutschland'] ?? 0) + 5;
			$scores['community'] = ($scores['community'] ?? 0) + 1;
		}
		if (preg_match('/\b(usa|united states|washington|china|beijing|taiwan|india|pakistan|middle east|nahost|gaza|israel|iran|lebanon|libanon|syria|syrien|asia|afrika|africa|latin america|lateinamerika|australia|australien|brisbane)\b/u', $text)) {
			$scores['world'] = ($scores['world'] ?? 0) + 7;
			$scores['europa'] = max(0, (int) ($scores['europa'] ?? 0) - 2);
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 4);
			$scores['bayern'] = max(0, (int) ($scores['bayern'] ?? 0) - 3);
		}
		if (preg_match('/\b(ukrainische gemeinde|ukrainian community|ukrainische initiative|netzwerktreffen|begegnung|ehrenamt|freiwillig|solidarit[aä]t|громад|зустріч|ініціатив)\b/u', $text)) {
			$scores['community'] = ($scores['community'] ?? 0) + 5;
			$scores['politik'] = max(0, (int) ($scores['politik'] ?? 0) - 1);
		}
		if (preg_match('/\b(börse|markt|kapitalmarkt|unternehmen|investor|aktie|aktien|rendite|finanz|tarif|preise|spritpreis|kraftstoffpreis|ölpreis|oil price|energy price|dax|paypal|wallet|wirtschaftsdaten)\b/u', $text)) {
			$scores['wirtschaft'] = ($scores['wirtschaft'] ?? 0) + 6;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 1);
		}
		if (preg_match('/\b(eugh|europ(?:ä|ae)ischer gerichtshof|european court|eu court|gerichtshof der eu|luxemburg)\b/u', $text)) {
			$scores['europa'] = ($scores['europa'] ?? 0) + 10;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 4);
		}
		if (preg_match('/\b(kirchenaustritt|kirchliche arbeitgeber|caritas|diakonie|religionszugehörigkeit|diskriminierungsschutz)\b/u', $text)) {
			$scores['europa'] = ($scores['europa'] ?? 0) + 5;
			$scores['leben-in-deutschland'] = ($scores['leben-in-deutschland'] ?? 0) + 3;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 1);
		}
		if (preg_match('/\b(aufenthaltstitel|aufenthaltsrecht|anerkennung|einb[üu]rgerung|fiktionsbescheinigung|schutzstatus|wohngeld|kindergeld|ausl[aä]nderbeh[öo]rde|jobcenter|arbeitsagentur|bamf|germany4ukraine|make it in germany)\b/u', $text)) {
			$scores['leben-in-deutschland'] = ($scores['leben-in-deutschland'] ?? 0) + 6;
			$scores['politik'] = max(0, (int) ($scores['politik'] ?? 0) - 1);
		}
		if (preg_match('/\b(integrationskurs|sprachkurs|arbeitsmarkt|benefits|labour market|bürgergeld|jobcenter|arbeitsagentur|schutzstatus|ukrainian refugees|ukrainische geflüchtete)\b/u', $text)) {
			$scores['leben-in-deutschland'] = ($scores['leben-in-deutschland'] ?? 0) + 7;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 2);
		}
		if (preg_match('/\b(royal|palace|monarchy|prince|princess|king|queen|illinois|senate primary|british royal|london)\b/u', $text)) {
			$scores['world'] = ($scores['world'] ?? 0) + 7;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 4);
			$scores['bayern'] = max(0, (int) ($scores['bayern'] ?? 0) - 3);
		}
		if (preg_match('/\b(gaspreis|gaspreise|strompreis|tarif|tarife|kapitalmarkt|börse|finanzmarkt|investor)\b/u', $text)) {
			$scores['wirtschaft'] = ($scores['wirtschaft'] ?? 0) + 5;
			$scores['bayern'] = max(0, (int) ($scores['bayern'] ?? 0) - 2);
		}

		if (($scores['münchen'] ?? 0) > 0) {
			$scores['bayern'] = ($scores['bayern'] ?? 0) + 2;
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 2;
		}
		if (($scores['bayern'] ?? 0) > 0) {
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 2;
		}
		if (($scores['ukraine'] ?? 0) > 0) {
			$scores['politik'] = ($scores['politik'] ?? 0) + 1;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 1);
			$scores['world'] = max(0, (int) ($scores['world'] ?? 0) - 2);
		}
		if (($scores['sport'] ?? 0) > 0) {
			$scores['bayern'] = max(0, (int) ($scores['bayern'] ?? 0) - 1);
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 1);
			$scores['sport'] += 3;
		}
		if (($scores['kultur'] ?? 0) > 0) {
			$scores['politik'] = max(0, (int) ($scores['politik'] ?? 0) - 1);
			$scores['kultur'] += 2;
		}
		if (preg_match('/\b(trump|nato|hormus|iran|libanon|israel|hezbollah)\b/u', $text)) {
			$scores['politik'] = ($scores['politik'] ?? 0) + 2;
			$scores['world'] = ($scores['world'] ?? 0) + 4;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 3);
			$scores['bayern'] = max(0, (int) ($scores['bayern'] ?? 0) - 2);
		}
		if (($scores['world'] ?? 0) > 0) {
			$scores['politik'] = max(0, (int) ($scores['politik'] ?? 0) - 1);
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 1);
		}
		if (($scores['wirtschaft'] ?? 0) > 0) {
			$scores['community'] = max(0, (int) ($scores['community'] ?? 0) - 2);
		}
		if (($scores['world'] ?? 0) > 0) {
			$scores['community'] = max(0, (int) ($scores['community'] ?? 0) - 2);
		}

		$priority = [
			'ukraine' => 110,
			'münchen' => 106,
			'bayern' => 104,
			'deutschland' => 102,
			'leben-in-deutschland' => 100,
			'sport' => 96,
			'kultur' => 94,
			'community' => 92,
			'wirtschaft' => 90,
			'world' => 88,
			'politik' => 82,
			'europa' => 80,
		];

		uksort($scores, static function (string $a, string $b) use ($scores, $priority): int {
			$scoreA = (int) ($scores[$a] ?? 0);
			$scoreB = (int) ($scores[$b] ?? 0);
			if ($scoreA !== $scoreB) {
				return $scoreB <=> $scoreA;
			}
			return ((int) ($priority[$b] ?? 0)) <=> ((int) ($priority[$a] ?? 0));
		});

		foreach ($scores as $slug => $score) {
			if ($score > 0) {
				return $slug;
			}
		}

		return $source_bias !== '' ? sanitize_title($source_bias) : 'deutschland';
	}

	public static function refine_with_event_context(string $current, array $dossier = [], string $title = '', string $content = ''): string {
		$current = sanitize_title($current);
		$event = is_array($dossier['event_context'] ?? null) ? $dossier['event_context'] : [];
		if ($event === []) {
			return $current !== '' ? $current : self::detect($title, $content, '');
		}

		$kind = (string) ($event['kind'] ?? '');
		$eventText = mb_strtolower(trim(implode(' ', array_filter([
			(string) ($event['event_title'] ?? ''),
			(string) ($event['venue'] ?? ''),
			(string) ($event['stage'] ?? ''),
			(string) ($event['next_step'] ?? ''),
			implode(' ', array_map('strval', (array) ($event['participants'] ?? []))),
			implode(' ', array_map('strval', (array) ($event['fact_snippets'] ?? []))),
			$title,
			wp_strip_all_tags($content),
		]))));

		if ($kind === 'sport') {
			if (
				preg_match('/\b(match|spiel|anpfiff|rückspiel|hinspiel|achtelfinale|viertelfinale|halbfinale|schiedsrichter|trainer|galatasaray|liverpool|bundesliga|champions league|paralymp)\b/u', $eventText) === 1
				|| count((array) ($event['participants'] ?? [])) >= 2
			) {
				return 'sport';
			}
		}

		if ($kind === 'kultur') {
			if (preg_match('/\b(the voice|tv|show|moderator|moderation|jury|casting|unterhaltungsshow|festival|premiere)\b/u', $eventText) === 1) {
				return 'kultur';
			}
		}

		if ($kind === 'community') {
			if (preg_match('/\b(jobcenter|aufenthalt|versicherung|wohngeld|kindergeld|arbeitsagentur|anmeldung|sprechstunde|beratung)\b/u', $eventText) === 1) {
				return 'leben-in-deutschland';
			}
			if (preg_match('/\b(job fair|career fair|bildungsmesse|berufsmesse|karrieremesse|workshop|vereinstreffen|community|diaspora|treffen|event|registration)\b/u', $eventText) === 1) {
				return 'community';
			}
		}

		return $current !== '' ? $current : self::detect($title, $content, '');
	}

	private static function apply_semantic_focus(array &$scores, string $title, string $lead): void {
		$focus = $title . ' ' . mb_substr($lead, 0, 500);

		if (preg_match('/\b(bundespräsident|steinmeier)\b/u', $title) && preg_match('/\b(demokratie|freiheit|shoah|traditionen)\b/u', $focus)) {
			$scores['politik'] = ($scores['politik'] ?? 0) + 10;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 2);
		}

		if (preg_match('/\b(paypal|google wallet|zahlungen per smartphone|bezahlen am handy|nfc-zahlung)\b/u', $title . ' ' . $focus)) {
			$scores['wirtschaft'] = ($scores['wirtschaft'] ?? 0) + 10;
			$scores['deutschland'] = max(0, (int) ($scores['deutschland'] ?? 0) - 1);
		}

			if (preg_match('/\b(slapp|einschüchterungsklagen|schutz vor slapp-klagen|regierungsentwurf|recht und verbraucherschutz)\b/u', $title . ' ' . $focus)) {
				$scores['politik'] = ($scores['politik'] ?? 0) + 10;
				$scores['wirtschaft'] = max(0, (int) ($scores['wirtschaft'] ?? 0) - 3);
			}

			if (preg_match('/\b(politische[- ]werbung|transparenzgesetz|digitalausschuss|bundesregierung|gesetzentwurf|eu-verordnung)\b/u', $title . ' ' . $focus)) {
				$scores['politik'] = ($scores['politik'] ?? 0) + 12;
				$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 2;
				$scores['wirtschaft'] = max(0, (int) ($scores['wirtschaft'] ?? 0) - 6);
			}

		if (preg_match('/\b(kritis-dachgesetz|kritische infrastrukturen|schutz kritischer infrastrukturen)\b/u', $title . ' ' . $focus)) {
			$scores['deutschland'] = ($scores['deutschland'] ?? 0) + 9;
			$scores['wirtschaft'] = ($scores['wirtschaft'] ?? 0) + 3;
		}

		if (preg_match('/\b(ausstellung)\b/u', $title) && preg_match('/\b(volkskammer|demokratiegeschichte|bundestag|parlament)\b/u', $focus)) {
			$scores['politik'] = ($scores['politik'] ?? 0) + 8;
			$scores['kultur'] = ($scores['kultur'] ?? 0) + 4;
		}
	}

	private static function source_bias_should_apply(string $bias, string $text): bool {
		$bias = sanitize_title($bias);
		if ($bias === '') {
			return false;
		}
		return match ($bias) {
			'ukraine' => preg_match('/\b(ukraine|ukrain|kyiv|kiew|київ|україн|zelensky|selensky|moskau|kreml|krieg|angriff|raketen|russland|росі|львів|lviv)\b/u', $text) === 1,
			'world' => preg_match('/\b(world|welt|usa|washington|china|taiwan|nahost|middle east|gaza|israel|iran|syria|syrien|afrika|africa|australia|australien)\b/u', $text) === 1,
			default => true,
		};
	}
}
