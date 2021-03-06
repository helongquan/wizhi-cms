<?php

/**
 * 文章模型
 *
 */

namespace Wizhi\Models;

use Illuminate\Database\Eloquent\Model;
use Wizhi\Models\Traits\CreatedAtTrait;
use Wizhi\Models\Traits\UpdatedAtTrait;

class Post extends Model {
	use CreatedAtTrait, UpdatedAtTrait;

	const CREATED_AT = 'post_date';
	const UPDATED_AT = 'post_modified';

	/** @var array */
	protected static $postTypes = [];
	protected static $shortcodes = [];

	protected $table = 'posts';
	protected $primaryKey = 'ID';
	protected $dates = [ 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' ];
	protected $with = [ 'meta' ];

	protected $fillable = [
		'post_content',
		'post_title',
		'post_excerpt',
		'post_type',
		'to_ping',
		'pinged',
		'post_content_filtered',
	];

	/**
	 * The accessors to append to the model's array form.
	 *
	 * @var array
	 */
	protected $appends = [
		'title',
		'slug',
		'content',
		'type',
		'mime_type',
		'url',
		'author_id',
		'parent_id',
		'created_at',
		'updated_at',
		'excerpt',
		'status',
		'image',

		// Terms inside all taxonomies
		'terms',

		// Terms analysis
		'main_category',
		'keywords',
		'keywords_str',
	];


	/**
	 * Post constructor.
	 *
	 * @param array $attributes
	 */
	public function __construct( array $attributes = [] ) {
		foreach ( $this->fillable as $field ) {
			if ( ! isset( $attributes[ $field ] ) ) {
				$attributes[ $field ] = '';
			}
		}

		parent::__construct( $attributes );
	}


	/**
	 * 根据文章类型过滤
	 *
	 * @param        $query
	 * @param string $type
	 *
	 * @return mixed
	 */
	public function scopeType( $query, $type = 'post' ) {
		return $query->where( 'post_type', '=', $type );
	}


	/**
	 * 根据文章状态过滤
	 *
	 * @param        $query
	 * @param string $status
	 *
	 * @return mixed
	 */
	public function scopeStatus( $query, $status = 'publish' ) {
		return $query->where( 'post_status', '=', $status );
	}


	/**
	 * 根据作者过滤
	 *
	 * @param      $query
	 * @param null $author
	 *
	 * @return mixed
	 */
	public function scopeAuthor( $query, $author = null ) {
		if ( $author ) {
			return $query->where( 'post_author', '=', $author );
		}

		return false;
	}


	/**
	 * 文章元数据
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function meta() {
		return $this->hasMany( 'Wizhi\Models\PostMeta', 'post_id' );
	}


	/**
	 * 文章自定义字段
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany|\Wizhi\Models\PostMetaCollection
	 */
	public function fields() {
		return $this->meta();
	}


	/**
	 * 文章缩略图
	 *
	 * @return mixed
	 */
	public function thumbnail() {
		return $this->hasOne( 'Wizhi\Models\ThumbnailMeta', 'post_id' )
		            ->where( 'meta_key', '_thumbnail_id' );
	}


	/**
	 * 分类法关系
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function taxonomies() {
		return $this->belongsToMany( 'Wizhi\Models\TermTaxonomy', 'term_relationships', 'object_id', 'term_taxonomy_id' );
	}

	/**
	 * 评论关系
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function comments() {
		return $this->hasMany( 'Wizhi\Models\Comment', 'comment_post_ID' );
	}


	/**
	 * 作者关系
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function author() {
		return $this->belongsTo( 'Wizhi\Models\User', 'post_author' );
	}


	/**
	 * 父级文章
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function parent() {
		return $this->belongsTo( 'Wizhi\Models\Post', 'post_parent' );
	}


	/**
	 * 文章附件
	 *
	 * @return mixed
	 */
	public function attachment() {
		return $this->hasMany( 'Wizhi\Models\Post', 'post_parent' )->where( 'post_type', 'attachment' );
	}


	/**
	 * 文章版本
	 *
	 * @return mixed
	 */
	public function revision() {
		return $this->hasMany( 'Wizhi\Models\Post', 'post_parent' )->where( 'post_type', 'revision' );
	}


	/**
	 * Overriding newQuery() to the custom PostBuilder with some interesting methods.
	 *
	 * @param bool $excludeDeleted
	 *
	 * @return \Wizhi\Models\PostBuilder
	 */
	public function newQuery( $excludeDeleted = true ) {
		$builder = new PostBuilder( $this->newBaseQueryBuilder() );
		$builder->setModel( $this )->with( $this->with );
		// disabled the default orderBy because else Post::all()->orderBy(..)
		// is not working properly anymore.
		// $builder->orderBy('post_date', 'desc');

		if ( isset( $this->postType ) and $this->postType ) {
			$builder->type( $this->postType );
		}

		if ( $excludeDeleted and $this->softDelete ) {
			$builder->whereNull( $this->getQualifiedDeletedAtColumn() );
		}

		return $builder;
	}


	/**
	 * 以文章原生数据的方式获取文章元数据的魔术方法
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	public function __get( $key ) {
		if ( ( $value = parent::__get( $key ) ) !== null ) {
			return $value;
		}

		if ( ! property_exists( $this, $key ) ) {
			if ( property_exists( $this, $this->primaryKey ) && isset( $this->meta->$key ) ) {
				return $this->meta->$key;
			}
		} elseif ( isset( $this->$key ) and empty( $this->$key ) ) {
			// fix for menu items when chosing category to show
			if ( in_array( $key, [ 'post_title', 'post_name' ] ) ) {
				$type     = $this->meta->_menu_item_object;
				$taxonomy = null;

				// Support certain types of meta objects
				if ( $type == 'category' ) {
					$taxonomy = $this->meta()->where( 'meta_key', '_menu_item_object_id' )
					                 ->first()->taxonomy( 'meta_value' )->first();
				} elseif ( $type == 'post_tag' ) {
					$taxonomy = $this->meta()->where( 'meta_key', '_menu_item_object_id' )
					                 ->first()->taxonomy( 'meta_value' )->first();
				} elseif ( $type == 'post' ) {
					$post = $this->meta()->where( 'meta_key', '_menu_item_object_id' )
					             ->first()->post( true )->first();

					return $post->$key;
				}

				if ( isset( $taxonomy ) && $taxonomy->exists ) {
					if ( $key == 'post_title' ) {
						return $taxonomy->name;
					} elseif ( $key == 'post_name' ) {
						return $taxonomy->slug;
					}
				}
			}
		}

		return false;
	}


	/**
	 * 保存文章元数据
	 *
	 * @param array $options
	 *
	 * @return bool
	 */
	public function save( array $options = [] ) {
		if ( isset( $this->attributes[ $this->primaryKey ] ) ) {
			$this->meta->save( $this->attributes[ $this->primaryKey ] );
		}

		return parent::save( $options );
	}

	/**
	 * 文章元数据过滤范围
	 *
	 * @param      $query
	 * @param      $meta
	 * @param null $value
	 *
	 * @return \Illuminate\Database\Eloquent\Collection
	 */
	public function scopeHasMeta( $query, $meta, $value = null ) {
		return $query->whereHas( 'meta', function ( $query ) use ( $meta, $value ) {
			$query->where( 'meta_key', $meta );
			if ( ! is_null( $value ) ) {
				$query->{is_array( $value ) ? 'whereIn' : 'where'}( 'meta_value', $value );
			}
		} );
	}

	/**
	 * 文章是否包含某个分类法项目
	 *
	 * @param string $taxonomy
	 * @param string $term
	 *
	 * @return bool
	 */
	public function hasTerm( $taxonomy, $term ) {
		return isset( $this->terms[ $taxonomy ] ) && isset( $this->terms[ $taxonomy ][ $term ] );
	}


	/**
	 * 获取标题属性
	 *
	 * @return string
	 */
	public function getTitleAttribute() {
		return $this->post_title;
	}

	/**
	 * 获取别名属性
	 *
	 * @return string
	 */
	public function getSlugAttribute() {
		return $this->post_name;
	}

	/**
	 * 获取内容属性
	 *
	 * @return string
	 */
	public function getContentAttribute() {
		if ( empty( self::$shortcodes ) ) {
			return $this->post_content;
		}

		return $this->stripShortcodes( $this->post_content );
	}

	/**
	 * 获取文章类型属性
	 *
	 * @return string
	 */
	public function getTypeAttribute() {
		return $this->post_type;
	}

	/**
	 * 获取 mime 类型属性
	 *
	 * @return string
	 */
	public function getMimeTypeAttribute() {
		return $this->post_mime_type;
	}

	/**
	 * 获取 URL 属性
	 *
	 * @return string
	 */
	public function getUrlAttribute() {
		return $this->guid;
	}

	/**
	 * 获取作者 ID 属性
	 *
	 * @return int
	 */
	public function getAuthorIdAttribute() {
		return $this->post_author;
	}

	/**
	 * 获取父级文章
	 *
	 * @return int
	 */
	public function getParentIdAttribute() {
		return $this->post_parent;
	}


	/**
	 * 获取发布日期
	 *
	 * @return string
	 */
	public function getCreatedAtAttribute() {
		return $this->post_date;
	}


	/**
	 * 获取更新日期
	 *
	 * @return string
	 */
	public function getUpdatedAtAttribute() {
		return $this->post_modified;
	}

	/**
	 * 获取文章摘要
	 *
	 * @return string
	 */
	public function getExcerptAttribute() {
		if ( empty( self::$shortcodes ) ) {
			return $this->post_excerpt;
		}

		return $this->stripShortcodes( $this->post_excerpt );
	}

	/**
	 * 获取文章状态
	 *
	 * @return string
	 */
	public function getStatusAttribute() {
		return $this->post_status;
	}

	/**
	 * 如果有特色图片、获取热色图片
	 * 查找e _thumbnail_id 元数据
	 *
	 * @return string
	 */
	public function getImageAttribute() {
		if ( $this->thumbnail and $this->thumbnail->attachment ) {
			return $this->thumbnail->attachment->guid;
		}

		return false;
	}

	/**
	 * Gets all the terms arranged taxonomy => terms[].
	 *
	 * @return array
	 */
	public function getTermsAttribute() {
		$taxonomies = $this->taxonomies;
		$terms      = [];

		foreach ( $taxonomies as $taxonomy ) {
			$taxonomyName                                        = $taxonomy[ 'taxonomy' ] == 'post_tag' ? 'tag' : $taxonomy[ 'taxonomy' ];
			$terms[ $taxonomyName ][ $taxonomy->term[ 'slug' ] ] = $taxonomy->term[ 'name' ];
		}

		return $terms;
	}

	/**
	 * 获取分类法中的第一个分类项目
	 *
	 * @return string
	 */
	public function getMainCategoryAttribute() {
		$mainCategory = 'Uncategorized';

		if ( ! empty( $this->terms ) ) {
			$taxonomies = array_values( $this->terms );

			if ( ! empty( $taxonomies[ 0 ] ) ) {
				$terms        = array_values( $taxonomies[ 0 ] );
				$mainCategory = $terms[ 0 ];
			}
		}

		return $mainCategory;
	}

	/**
	 * 获取关键词为数组
	 *
	 * @return array
	 */
	public function getKeywordsAttribute() {
		$keywords = [];

		if ( $this->terms ) {
			foreach ( $this->terms as $taxonomy ) {
				foreach ( $taxonomy as $term ) {
					$keywords[] = $term;
				}
			}
		}

		return $keywords;
	}

	/**
	 * 获取关键词字符串
	 *
	 * @return string
	 */
	public function getKeywordsStrAttribute() {
		return implode( ',', (array) $this->keywords );
	}

	/**
	 * Overrides default behaviour by instantiating class based on the $attributes->post_type value.
	 *
	 * By default, this method will always return an instance of the calling class. However if post types have
	 * been registered with the Post class using the registerPostType() static method, this will now return an
	 * instance of that class instead.
	 *
	 * If the post type string from $attributes->post_type does not appear in the static $postTypes array,
	 * then the class instantiated will be the called class (the default behaviour of this method).
	 *
	 * @param array $attributes
	 * @param null  $connection
	 *
	 * @return mixed
	 */
	public function newFromBuilder( $attributes = [], $connection = null ) {
		if ( is_object( $attributes ) && array_key_exists( $attributes->post_type, static::$postTypes ) ) {
			$class = static::$postTypes[ $attributes->post_type ];
		} elseif ( is_array( $attributes ) && array_key_exists( $attributes[ 'post_type' ], static::$postTypes ) ) {
			$class = static::$postTypes[ $attributes[ 'post_type' ] ];
		} else {
			$class = get_called_class();
		}

		$model         = new $class( [] );
		$model->exists = true;

		$model->setRawAttributes( (array) $attributes, true );
		$model->setConnection( $connection ? : $this->connection );

		return $model;
	}

	/**
	 * Register your Post Type classes here to have them be instantiated instead of the standard Post model.
	 *
	 * This method allows you to register classes that will be used for specific post types as defined in the post_type
	 * column of the wp_posts table. If a post type is registered here, when a Post object is returned from the posts
	 * table it will be automatically converted into the appropriate class for its post type.
	 *
	 * If you register a Page class for the post_type 'page', then whenever a Post is fetched from the database that has
	 * its post_type has 'page', it will be returned as a Page instance, instead of the default and generic
	 * Post instance.
	 *
	 * @param string $name  The name of the post type (e.g. 'post', 'page', 'custom_post_type')
	 * @param string $class The class that represents the post type model (e.g. 'Post', 'Page', 'CustomPostType')
	 */
	public static function registerPostType( $name, $class ) {
		static::$postTypes[ $name ] = $class;
	}

	/**
	 * 清理已注册的文章类型
	 */
	public static function clearRegisteredPostTypes() {
		static::$postTypes = [];
	}

	/**
	 * 添加简码处理工具
	 *
	 * @param string $tag      简码标签
	 * @param        $function $function 简码处理功能
	 */
	public static function addShortcode( $tag, $function ) {
		self::$shortcodes[ $tag ] = $function;
	}

	/**
	 * 移除简码处理工具
	 *
	 * @param string $tag 简码标签
	 */
	public static function removeShortcode( $tag ) {
		if ( isset( self::$shortcodes[ $tag ] ) ) {
			unset( self::$shortcodes[ $tag ] );
		}
	}

	/**
	 * 处理简码
	 *
	 * @param string $content 简码内容
	 *
	 * @return string
	 */
	public function stripShortcodes( $content ) {
		$facade = new ShortcodeFacade();
		foreach ( self::$shortcodes as $tag => $func ) {
			$facade->addHandler( $tag, $func );
		}

		return $facade->process( $content );
	}

	/**
	 * 获取文章格式，类似 WP get_post_format() 功能
	 *
	 * @return bool|string
	 */
	public function getFormat() {
		$taxonomy = $this->taxonomies()->where( 'taxonomy', 'post_format' )->first();
		if ( $taxonomy and $taxonomy->term ) {
			return str_replace( 'post-format-', '', $taxonomy->term->slug );
		}

		return false;
	}
}
