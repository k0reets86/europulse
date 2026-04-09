<?php
/**
 * EuroPulse AutoPilot v2.1 — Google News RSS Reader
 *
 * Uses the Google News RSS search endpoint instead of the HTML page.
 * Caches per-query for 30 minutes via WP transients.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class EPV2_Google_News {

    private const CACHE_TTL   = 1800;
    private const URL_CACHE_TTL = 21600;
    private const RSS_BASE    = 'https://news.google.com/rss/search';
    private const DECODE_ENDPOINT = 'https://news.google.com/_/DotsSplashUi/data/batchexecute?rpcids=Fbv4je';
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_3) AppleWebKit/605.1.15 Version/17.2 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64; rv:123.0) Gecko/20100101 Firefox/123.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0',
    ];

    /**
     * @return array<array{title:string, url:string, excerpt:string, published:string, source:string}>
     */
    public static function fetch(
        string $query,
        string $lang    = 'de',
        string $country = 'DE',
        int    $limit   = 10
    ): array {
        $cache_key = 'epv2_gn_' . md5( $query . $lang . $country );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false && is_array( $cached ) ) {
            return array_slice( $cached, 0, $limit );
        }

        $url = add_query_arg( [
            'q'    => rawurlencode( $query ),
            'hl'   => $lang,
            'gl'   => $country,
            'ceid' => $country . ':' . $lang,
        ], self::RSS_BASE );

        $ua       = self::USER_AGENTS[ array_rand( self::USER_AGENTS ) ];
        $response = wp_remote_get( $url, [
            'timeout'    => 20,
            'user-agent' => $ua,
            'headers'    => [
                'Accept'          => 'application/rss+xml, application/xml, text/xml, */*',
                'Accept-Language' => $lang . '-' . $country . ',' . $lang . ';q=0.9',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            EPV2_Logger::warning( 'google_news', 'Google News request error', [
                'query' => $query,
                'error' => $response->get_error_message(),
            ] );
            return [];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            EPV2_Logger::warning( 'google_news', 'Google News non-200 response', [
                'query' => $query,
                'code'  => $code,
            ] );
            set_transient( $cache_key, [], 300 ); // 5 min backoff
            return [];
        }

        $content_type = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
        if ( $content_type !== '' && strpos( $content_type, 'xml' ) === false && strpos( $content_type, 'rss' ) === false ) {
            EPV2_Logger::warning( 'google_news', 'Google News unexpected content type', [
                'query'        => $query,
                'content_type' => $content_type,
            ] );
            set_transient( $cache_key, [], 300 );
            return [];
        }

        $body  = wp_remote_retrieve_body( $response );
        if ( trim( $body ) === '' || ( stripos( $body, '<rss' ) === false && stripos( $body, '<feed' ) === false ) ) {
            EPV2_Logger::warning( 'google_news', 'Google News invalid XML body', [
                'query' => $query,
            ] );
            set_transient( $cache_key, [], 300 );
            return [];
        }
        $items = self::parse_rss( $body );
        set_transient( $cache_key, $items, self::CACHE_TTL );

        return array_slice( $items, 0, $limit );
    }

    public static function resolve_url( string $url ): string {
        $url = esc_url_raw( trim( $url ) );
        if ( ! self::is_google_news_wrapper_url( $url ) ) {
            return $url;
        }
        $cache_key = 'epv2_gn_url_' . md5( $url );
        $cached = get_transient( $cache_key );
        if ( is_string( $cached ) && $cached !== '' && ! self::is_google_news_wrapper_url( $cached ) ) {
            return $cached;
        }
        if ( self::is_google_news_wrapper_url( (string) $cached ) ) {
            delete_transient( $cache_key );
        }

        $resolved = self::decode_wrapper_url( $url );
        if ( $resolved === '' ) {
            $resolved = self::resolve_via_head_redirect( $url );
        }
        if ( $resolved === '' ) {
            $resolved = $url;
        }
        if ( ! self::is_google_news_wrapper_url( $resolved ) ) {
            set_transient( $cache_key, $resolved, self::URL_CACHE_TTL );
        }
        return $resolved;
    }

    public static function normalize_item_source( object $item ): object {
        $normalized = clone $item;
        $urls = self::normalize_queue_row_urls( $normalized );
        if ( isset( $urls['original_url'] ) ) {
            $normalized->original_url = $urls['original_url'];
        }
        if ( isset( $urls['canonical_url'] ) ) {
            $normalized->canonical_url = $urls['canonical_url'];
        }
        return $normalized;
    }

    public static function normalize_queue_row_urls( object $item ): array {
        $original = esc_url_raw( trim( (string) ( $item->original_url ?? '' ) ) );
        $canonical = esc_url_raw( trim( (string) ( $item->canonical_url ?? '' ) ) );
        $wrapper = '';
        if ( self::is_google_news_wrapper_url( $original ) ) {
            $wrapper = $original;
        } elseif ( self::is_google_news_wrapper_url( $canonical ) ) {
            $wrapper = $canonical;
        } else {
            return [];
        }

        $resolved = self::resolve_url( $wrapper );
        if ( $resolved === '' || self::is_google_news_wrapper_url( $resolved ) ) {
            return [];
        }

        $fields = [];
        if ( $original !== $resolved ) {
            $fields['original_url'] = $resolved;
        }
        if ( $canonical !== $wrapper ) {
            $fields['canonical_url'] = $wrapper;
        }
        return $fields;
    }

    private static function decode_wrapper_url( string $url ): string {
        $article_id = self::article_id_from_url( $url );
        if ( $article_id === '' ) {
            return '';
        }
        $params = self::fetch_decoding_params( $article_id );
        if ( $params === [] ) {
            return '';
        }
        return self::decode_article_request(
            $params['gn_art_id'] ?? '',
            $params['timestamp'] ?? '',
            $params['signature'] ?? ''
        );
    }

    private static function resolve_via_head_redirect( string $url ): string {
        $ua       = self::USER_AGENTS[ array_rand( self::USER_AGENTS ) ];
        $response = wp_remote_head( $url, [
            'timeout'     => 10,
            'redirection' => 5,
            'user-agent'  => $ua,
        ] );
        if ( is_wp_error( $response ) ) {
            return '';
        }
        $location = wp_remote_retrieve_header( $response, 'location' );
        if ( ! is_string( $location ) || $location === '' ) {
            return '';
        }
        $location = esc_url_raw( $location );
        if ( self::is_google_news_wrapper_url( $location ) ) {
            return '';
        }
        return $location;
    }

    private static function is_google_news_wrapper_url( string $url ): bool {
        if ( $url === '' || strpos( $url, 'news.google.com/' ) === false ) {
            return false;
        }
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        return preg_match( '#/(rss/)?(articles|read)/#', $path ) === 1;
    }

    private static function article_id_from_url( string $url ): string {
        $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ) );
        if ( $path === '' ) {
            return '';
        }
        $parts = array_values( array_filter( explode( '/', $path ) ) );
        $article_id = (string) end( $parts );
        return preg_match( '/^[A-Za-z0-9_-]{20,}$/', $article_id ) === 1 ? $article_id : '';
    }

    private static function fetch_decoding_params( string $article_id ): array {
        if ( $article_id === '' ) {
            return [];
        }
        $html = self::http_get_body( 'https://news.google.com/rss/articles/' . rawurlencode( $article_id ), [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ] );
        if ( $html === '' ) {
            return [];
        }
        if (
            preg_match( '/data-n-a-sg="([^"]+)"/', $html, $signature )
            && preg_match( '/data-n-a-ts="([^"]+)"/', $html, $timestamp )
        ) {
            return [
                'gn_art_id' => $article_id,
                'signature' => (string) ( $signature[1] ?? '' ),
                'timestamp' => (string) ( $timestamp[1] ?? '' ),
            ];
        }
        return [];
    }

    private static function decode_article_request( string $article_id, string $timestamp, string $signature ): string {
        if ( $article_id === '' || $timestamp === '' || $signature === '' ) {
            return '';
        }
        $request = sprintf(
            '["garturlreq",[["X","X",["X","X"],null,null,1,1,"US:en",null,1,null,null,null,null,null,0,1],"X","X",1,[1,1,1],1,1,null,0,0,null,0],"%s",%s,"%s"]',
            $article_id,
            preg_replace( '/[^0-9]/', '', $timestamp ),
            $signature
        );
        $payload = 'f.req=' . rawurlencode( wp_json_encode( [ [ [ 'Fbv4je', $request, null, 'generic' ] ] ], JSON_UNESCAPED_UNICODE ) );
        $body = self::http_post_body( self::DECODE_ENDPOINT, $payload, [
            'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
            'Referer: https://news.google.com/',
            'Accept-Language: en-US,en;q=0.9',
        ] );
        return self::extract_decoded_url_from_batch_response( $body );
    }

    private static function extract_decoded_url_from_batch_response( string $body ): string {
        if ( $body === '' ) {
            return '';
        }
        $parts = preg_split( "/\n\n/", $body, 2 );
        if ( isset( $parts[1] ) ) {
            $rows = json_decode( $parts[1], true );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    if ( ! is_array( $row ) || ! is_string( $row[2] ?? null ) ) {
                        continue;
                    }
                    $decoded = json_decode( $row[2], true );
                    if ( is_array( $decoded ) && ( $decoded[0] ?? '' ) === 'garturlres' ) {
                        $candidate = esc_url_raw( (string) ( $decoded[1] ?? '' ) );
                        if ( $candidate !== '' && ! self::is_google_news_wrapper_url( $candidate ) ) {
                            return $candidate;
                        }
                    }
                }
            }
        }
        if ( preg_match( '/\["garturlres","(https?:\\\\\/\\\\\/[^"]+)"/', $body, $match ) ) {
            $candidate = stripcslashes( (string) $match[1] );
            $candidate = esc_url_raw( $candidate );
            if ( $candidate !== '' && ! self::is_google_news_wrapper_url( $candidate ) ) {
                return $candidate;
            }
        }
        return '';
    }

    private static function http_get_body( string $url, array $headers = [] ): string {
        if ( function_exists( 'curl_init' ) ) {
            $ch = curl_init( $url );
            if ( $ch !== false ) {
                $ua = self::USER_AGENTS[ array_rand( self::USER_AGENTS ) ];
                curl_setopt_array( $ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_ENCODING => '',
                    CURLOPT_USERAGENT => $ua,
                    CURLOPT_HTTPHEADER => $headers,
                ] );
                $body = curl_exec( $ch );
                $code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
                curl_close( $ch );
                if ( is_string( $body ) && $code === 200 ) {
                    return $body;
                }
            }
        }

        $header_lines = $headers;
        $header_lines[] = 'User-Agent: ' . self::USER_AGENTS[ array_rand( self::USER_AGENTS ) ];
        $context = stream_context_create( [
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
                'header' => implode( "\r\n", $header_lines ) . "\r\n",
            ],
        ] );
        $body = @file_get_contents( $url, false, $context );
        return is_string( $body ) ? $body : '';
    }

    private static function http_post_body( string $url, string $payload, array $headers = [] ): string {
        if ( function_exists( 'curl_init' ) ) {
            $ch = curl_init( $url );
            if ( $ch !== false ) {
                $ua = self::USER_AGENTS[ array_rand( self::USER_AGENTS ) ];
                curl_setopt_array( $ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_ENCODING => '',
                    CURLOPT_USERAGENT => $ua,
                    CURLOPT_HTTPHEADER => $headers,
                ] );
                $body = curl_exec( $ch );
                $code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
                curl_close( $ch );
                if ( is_string( $body ) && $code === 200 ) {
                    return $body;
                }
            }
        }

        $header_lines = $headers;
        $header_lines[] = 'User-Agent: ' . self::USER_AGENTS[ array_rand( self::USER_AGENTS ) ];
        $context = stream_context_create( [
            'http' => [
                'method' => 'POST',
                'timeout' => 15,
                'ignore_errors' => true,
                'content' => $payload,
                'header' => implode( "\r\n", $header_lines ) . "\r\n",
            ],
        ] );
        $body = @file_get_contents( $url, false, $context );
        return is_string( $body ) ? $body : '';
    }

    private static function parse_rss( string $xml ): array {
        if ( trim( $xml ) === '' ) return [];
        libxml_use_internal_errors( true );
        $doc = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NOCDATA );
        if ( $doc === false ) return [];
        $channel = $doc->channel ?? null;
        if ( ! $channel ) return [];

        $items = [];
        foreach ( $channel->item as $item ) {
            $raw_url = (string) ( $item->link ?? '' );
            if ( $raw_url === '' ) continue;

            $title   = wp_strip_all_tags( (string) ( $item->title ?? '' ) );
            // Remove publisher suffix "Article title - Source Name"
            $title   = (string) preg_replace( '/ - [^-]{2,60}$/', '', $title );
            $excerpt = wp_strip_all_tags( (string) ( $item->description ?? '' ) );
            $pub     = (string) ( $item->pubDate ?? '' );
            $source  = isset( $item->source ) ? (string) $item->source : '';

            $items[] = [
                'title'     => $title,
                'url'       => $raw_url,
                'excerpt'   => $excerpt,
                'published' => $pub,
                'source'    => $source,
            ];
        }
        return $items;
    }
}
