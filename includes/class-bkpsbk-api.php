<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BKPSBK_API {

    const BASE_URL = 'https://api.businesskoro.com/api/v1/storefront';

    private static function build_headers( $api_key, $domain = '' ) {
        $headers = array(
            'x-api-key'    => trim( (string) $api_key ),
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        );

        $clean_domain = trim( (string) $domain );
        if ( '' !== $clean_domain ) {
            $headers['x-domain'] = $clean_domain;
        }

        return $headers;
    }

    public static function get_products( $api_key, $domain = '' ) {
        $api_key = trim( (string) $api_key );
        if ( '' === $api_key ) {
            return new WP_Error( 'bk_missing_key', 'API key is empty.' );
        }

        $url = self::BASE_URL . '/products';
        $response = wp_remote_get(
            $url,
            array(
                'headers' => self::build_headers( $api_key, $domain ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'bk_http_error', 'Request failed: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 ) {
            $err_msg = 'Api key Incorrect';
            if ( is_array( $data ) && ! empty( $data['message'] ) ) {
                $err_msg = sanitize_text_field( (string) $data['message'] );
            } elseif ( 401 === $code || 403 === $code ) {
                $err_msg = 'Api key Incorrect';
            }
            return new WP_Error( 'bk_api_error', $err_msg . ' (HTTP ' . $code . ')' );
        }

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'bk_invalid_json', 'Invalid JSON response from Business Koro API.' );
        }

        return $data;
    }

    public static function verify_credentials( $api_key, $domain = '' ) {
        $res = self::get_products( $api_key, $domain );
        if ( is_wp_error( $res ) ) {
            return array(
                'valid'   => false,
                'message' => 'Api key Incorrect',
            );
        }

        if ( isset( $res['data'] ) && is_array( $res['data'] ) ) {
            return array(
                'valid'   => true,
                'message' => 'Active',
            );
        }

        return array(
            'valid'   => false,
            'message' => 'Api key Incorrect',
        );
    }

    public static function create_order( $api_key, $domain = '', $payload = array() ) {
        $api_key = trim( (string) $api_key );
        if ( '' === $api_key ) {
            return new WP_Error( 'bk_missing_key', 'API key is empty.' );
        }

        $url = self::BASE_URL . '/orders';
        $response = wp_remote_post(
            $url,
            array(
                'headers' => self::build_headers( $api_key, $domain ),
                'body'    => wp_json_encode( $payload ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'bk_http_error', 'Order request failed: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 ) {
            $msg = 'Failed to send order to Business koro (HTTP ' . $code . ').';
            if ( is_array( $data ) && ! empty( $data['message'] ) ) {
                $msg = sanitize_text_field( (string) $data['message'] );
            }
            return new WP_Error( 'bk_order_error', $msg );
        }

        return is_array( $data ) ? $data : array( 'raw' => $body );
    }

    public static function get_order_status( $api_key, $domain = '', $bk_order_id = '' ) {
        $api_key     = trim( (string) $api_key );
        $bk_order_id = trim( (string) $bk_order_id );

        if ( '' === $api_key ) {
            return new WP_Error( 'bk_missing_key', 'API key is empty.' );
        }
        if ( '' === $bk_order_id ) {
            return new WP_Error( 'bk_missing_order_id', 'Business Koro Order ID is missing.' );
        }

        $url = self::BASE_URL . '/orders/' . rawurlencode( $bk_order_id );
        $response = wp_remote_get(
            $url,
            array(
                'headers' => self::build_headers( $api_key, $domain ),
                'timeout' => 25,
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'bk_http_error', 'Status check failed: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 ) {
            $msg = 'Failed to fetch order status (HTTP ' . $code . ').';
            if ( is_array( $data ) && ! empty( $data['message'] ) ) {
                $msg = sanitize_text_field( (string) $data['message'] );
            }
            return new WP_Error( 'bk_status_error', $msg );
        }

        return is_array( $data ) ? $data : array( 'raw' => $body );
    }
}
