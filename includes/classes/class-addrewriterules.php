<?php

class WP_AddRewriteRules {
    private $rule     = null;
    private $query    = null;
    private $callback = null;

    function __construct( $rule, $query, $callback ) {
        $this->rule     = $rule;
        $this->query    = $query;
        $this->callback = $callback;
		
        add_filter( 'query_vars', array( &$this, 'query_vars' ) );
		
        add_action( 'generate_rewrite_rules', array( &$this, 'generate_rewrite_rules' ) );
        add_action( 'wp', array( &$this, 'wp' ) );
    }

    public function generate_rewrite_rules( $wp_rewrite ) {
        $query = $this->query;
		
		if( strpos($this->query, '=') === FALSE ) {
            $query .= '=1';
		}


		$new_rules[$this->rule] = $wp_rewrite->index . '?' . $query;
		
        $wp_rewrite->rules = $new_rules + $wp_rewrite->rules;
    }

    private function parse_query( $query ) {
        $query = explode( '&', $query );
		
		if( is_array( $query ) && isset( $query[0] ) ) {
			$query = explode( '=', $query[0] );
		} else {
			$query = explode( '=', $query );
		}
		
		if( is_array( $query ) && isset( $query[0] ) ) {
			$query = $query[0];
		}

		return $query;
    }

    public function query_vars( $vars ) {
        $vars[] = $this->parse_query( $this->query );
		
        return $vars;
    }

    public function wp() {
        if( get_query_var( $this->parse_query( $this->query ) ) ) {
            call_user_func( $this->callback );
        }
    }
}
