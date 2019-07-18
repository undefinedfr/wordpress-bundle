<?php

namespace Metabolism\WordpressBundle\Helper;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SiteHealth {

	private $status = [];
	private $has_error = false;
	private $base_url = '';
	private $output = false;
	private $full = false;

	public function __construct(){

		$this->base_url = get_home_url();
		$this->output = $_REQUEST['output']??false;
		$this->full = $_REQUEST['full']??false;
		$this->password = $_SERVER['APP_PASSWORD']??false;
	}

	public function check(){

		$this->checkPosts();
		$this->checkTaxonomies();
		$this->checkPagesWithState();
		
		$status = $this->has_error ? 406 : 200;

		if( !$this->output ){
			$content = $this->has_error ? '0' : '1';
		}
		else{
			if( $this->output == 'json' ){
				$content = ['has_error'=>$this->has_error, 'status'=>$this->status];
				$response = new JsonResponse($content, $status);
		}
		else{
				$content = $this->_toHTML();
				$response = new Response($content, $status);
			}
		}

		$response->setSharedMaxAge(0);

		return $response;
	}

	private function _toHTML(){

		$html = '<html>';
		$html .= '<head><meta name="viewport" content="width=device-width, initial-scale=1">';
		$html .= '<title>Site Health</title>';
		$html .= '<link href="https://fonts.googleapis.com/css?family=Roboto&display=swap" rel="stylesheet">';
		$html .= '<link rel="stylesheet" href="https://unpkg.com/purecss@1.0.1/build/pure-min.css" crossorigin="anonymous">';
		$html .= '<style type="text/css">body{ padding: 20px; font-family: Roboto, sans-serif }</style>';
		$html .= '</head>';
		$html .= '<body><table class="pure-table pure-table-striped" style="width:100%">';
		$html .= '<thead><tr><th>Label</th><th>Url</th><th style="text-align:center">Code</th><th style="text-align:center">Empty</th><th style="text-align:center">Body</th><th style="text-align:center">Timing</th></tr></thead>';

		foreach ( $this->status as $status)
			$html .= '<tr><td>'.$status['label'].'</td><td><a href="'.$this->base_url.$status['url'].'" target="_blank">'.$status['url'].'</a></td><td style="text-align:center;color:'.($status['code']!=200?'red':'').'">'.$status['code'].'</td><td style="text-align:center">'.($status['empty']?'yes':'no').'</td><td style="text-align:center">'.($status['body']>0?'yes':'no').'</td><td style="text-align:center">'.$status['response_time'].'ms</td></tr>';

		$html .= '<table></body></html>';

		return $html;
	}

	private function getStatus($label, $url=''){

		if( is_wp_error($url) )
			return;

		$time_start = microtime(true);
		$response = wp_remote_get($this->base_url.$url.($this->password?'?APP_PASSWORD='.$this->password:''), ['timeout'=>30]);
		$time_end = microtime(true);

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$response_headers = wp_remote_retrieve_headers( $response );

		$status = [
			'label'=>$label,
			'url'=>$url,
			'code'=>$response_code,
			'response_time'=>round($time_end*1000-$time_start*1000),
			'empty'=>empty($response_body),
			'body'=>strpos($response_body, '</body>')>0
		];

		$status['valid'] = $status['code']==200 && !$status['empty'] && $status['body']>0;

		$this->has_error = $this->has_error || !$status['valid'];

		$this->status[] = $status;
	}

	private function checkPosts(){

		global $wp_post_types, $wp_rewrite;

		foreach ($wp_post_types as $post_type)
		{
			if( $post_type->public && ($post_type->publicly_queryable || $post_type->name == 'page') && !in_array($post_type->name, ['attachment']) ){

				$posts = get_posts(['post_type'=>$post_type->name, 'posts_per_page'=>($this->full?-1:1)]);

				foreach ($posts as $post){

					$url = get_permalink($post);
					$this->getStatus('Post '.$post_type->name, $url);
				}
				
				if( $post_type->has_archive ){

					$url = get_post_type_archive_link($post_type->name);
					$this->getStatus('Post '.$post_type->name.' archive', $url);
				}
			}
		}
	}

	private function checkPagesWithState(){

		global $_config;

		$page_states = $_config->get('page_states', []);

		foreach ($page_states as $state=>$label){

			$page = get_option('page_on_'.$state);

			if( !$page || is_wp_error($page) )
				continue;

			$url = get_page_link($page);
			$this->getStatus('State '.$state, $url);
		}

		$this->getStatus( 'Home', '/' );
	}

	private function checkTaxonomies(){

		global $wp_taxonomies;
		global $wp_rewrite;

		foreach ($wp_taxonomies as $taxonomy){

			//todo: better category handle
			if( $taxonomy->public && $taxonomy->publicly_queryable && !in_array($taxonomy->name, ['post_tag','post_format','category']) ){

				$terms = get_terms(['taxonomy'=>$taxonomy->name, 'number'=>($this->full?0:1)]);

				foreach ($terms as $term){

					$url = get_term_link($term, $taxonomy->name);
					$this->getStatus('Taxonomy '.$taxonomy->name, $url);
				}
			}
		}
	}
}
