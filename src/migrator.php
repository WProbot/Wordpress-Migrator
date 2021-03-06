<?php

namespace DannyBriff;

use DannyBriff\DomModifier;

Class Migrator {

  protected $importer;

  protected $destination_wordpress_rest_url;
  protected $old_domain;
  protected $new_domain;
  protected $wp_media_url;
  protected $wp_post_url;
  protected $username;
  protected $password;
  

  function __construct($_importer) {
    $this->loadImporter($_importer);

    $this->destination_wordpress_rest_url = getenv('DESTINATION_WORDPRESS_REST_URL'); 
    $this->old_domain = getenv('OLD_DOMAIN'); 
    $this->new_domain = getenv('NEW_DOMAIN'); 
    $this->wp_media_url = getenv('WP_MEDIA_URL'); 
    $this->wp_post_url = getenv('WP_POST_URL'); 
    $this->username = getenv('WP_USERNAME');
    $this->password = getenv('WP_PASSWORD');
  }

  function loadImporter($_importer)
  {
    switch($_importer) 
    {
      case 'wordpress':
          $this->importer = new \DannyBriff\importers\WordpressImporter(getenv('ORIGIN_BLOG_URL'));
        break;
      case 'wbwgn':
          $this->importer = new \DannyBriff\importers\WbWgnImporter(getenv('DB_DSN'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), getenv('AMOUNT'), getenv('PAGE'));
        break;
      default:
          throw new Exception('Importer does not exist');
        break;
    }
  }

  function run()
  {        
    //get data
    $blogs = $this->importer->getBlogs();

    foreach($blogs as $blog)
    {

      //if var is not of type Blog, skip execution
      if (!$this->isBlogObject($blog))
      {
        echo '------ Blog Object not of type Blog ! Skipping iteration ------';
        continue;
      }

      //if setting is on, change urls and update content before inserting new post    
      if (getenv('CHANGE_URLS') != 'false')
      {
        $urls_to_replace = $this->extractUrlsFromStringToReplace($blog->getContent());

        foreach ($urls_to_replace as $old_term=>$new_term)
        {
            $data = str_replace($old_term, $new_term, $blog->getContent());
            $blog->setContent($data);
        }
      }
      
      //execute html modifications
      if ($modification = json_decode(getenv('HTML_MODIFICATIONS'), true))
      {
        $dom_modifier = new DomModifier($blog->getContent());

        foreach($modification as $key => $value)
        {
          $dom_modifier->wrap_elements($key, $value['wrapper']);

          if (isset($value['attributes']))
          {
            foreach($value['attributes'] as $attribute_name => $attribute_payload)
            {
              $dom_modifier->add_attribute($key, $attribute_name, explode(' ', $attribute_payload['add']))
                          ->remove_attribute($key, $attribute_name, explode(' ', $attribute_payload['remove']));
            }
          }                      
        }    
        
        $blog->setContent($dom_modifier->get_html_content());
      }

      $this->insertPost($blog);

    }
  }

  function isBlogObject($object)
  {
    //if var is not of type Blog
    return ($object instanceof objects\Blog);
  }

  /**
   * Extract urls from string, import images to new application and overwrite urls to point to new domain
   */
  function extractUrlsFromStringToReplace($_data)
  {
    //Extract URLs
    $urls = getUrlsFromString($_data);

    //Remove dupliactes
    $unique_urls = array_unique($urls);

    $urls_to_replace = [];
    foreach($unique_urls as $url) 
    {      
      //Check if URL is of image
      if(urlIsImage($url))
      {
        try
        {
          //if we shall not import images from a third party source, skip this iteration
          if (getenv('CHANGE_EXTERNAL_URL') == false && strpos($url, $this->old_domain) == false )
          {
            continue;
          }

          $uploaded_image_url = $this->transferMedia($url);
          //GET THE URL OF THE NEWLY UPLOADED IMAGE
          $urls_to_replace[$url] = $uploaded_image_url->guid->rendered;
        }
        catch(Exception $e){
          var_dump($e);
        }        
      }
      else 
      {
        //if url is not an image, in case it is an internal link, replace with new domain
        $urls_to_replace[$url] = str_replace($this->old_domain, $this->new_domain, $url);
      }
    } 

    return $urls_to_replace;
  }

  function transferMedia($_url)
  {
    //get image and call destination api to store image
    $image = file_get_contents($_url);
    $image_name = basename($_url);
    //store it
    $response = makeCurlCall($this->username, $this->password, $this->wp_media_url, $image,  ['Content-Disposition: form-data; filename="'.$image_name.'"']);
    
    return $response; 
  }

  function insertAndSetFeaturedImage($post)
  {
    if ($post->getFeaturedImage() !== null && trim($post->getFeaturedImage()) !== '')
    {
      //upload image
      $image = $this->transferMedia($post->getFeaturedImage());
      //retrieve newly uploaded image id
      $post->setFeaturedImage($image->id);
    }

    return $post;
  }

  function insertPost($post)
  {    

    $post = $this->insertAndSetFeaturedImage($post);

    $data = [
      'date' => $post->getDate(),
      'slug' => $post->getSlug(),
      'featured_media' => $post->getFeaturedImage(),
      'status' => $post->getStatus(),
      'title' => $post->getTitle(),
      'content' => $post->getContent(),
      'excerpt' => $post->getExcerpt()
    ];
    
    $response = makeCurlCall($this->username, $this->password, $this->wp_post_url, $data);
  }

}