<?php
/*
Plugin Name: WPBlogSyn
Plugin URI: http://obridgegroup.net/
Description: 一个利用xmlrpc远程同步的程序
Version: 1.0
Author: Obridge Group
*/

// 更新设置
add_action('admin_init','blogsync_update_options');
function blogsync_update_options(){
	if(!is_admin() && !current_user_can('edit_theme_options'))return;
	if(!empty($_POST) && isset($_POST['page']) && $_POST['page'] == $_GET['page'] && isset($_POST['action']) && $_POST['action'] == 'blogsync_update_options'){
	
		$name 	= sanitize_text_field( $_POST['name'] );
		$url 	= sanitize_text_field( $_POST['url'] );
		$user 	= sanitize_text_field( $_POST['user'] );
		$pwd	= sanitize_text_field( $_POST['pwd'] );	
		$cat	= sanitize_text_field( $_POST['cat'] );
		
		$update_blogs['name'] = $name;
		$update_blogs['url'] = $url;
		$update_blogs['user'] = $user;
		$update_blogs['pwd'] = $pwd;
		$update_blogs['cat'] = $cat;
		
		update_option('blogsync',$update_blogs);
		wp_redirect(add_query_arg(array('time'=>time())));
		exit;
	}
}
// 添加菜单和设置页面
add_action('admin_menu','blogsync_menu');
function blogsync_menu(){
	add_plugins_page('同步设置','同步设置','edit_theme_options','同步设置','blogsync_options');
}
// 设置页面
function blogsync_options(){
?>
<style>
.order-num{width:60px;}
.short-text{width:150px;}
#wp2wp-blogs-list-table *{text-align:center;}
</style>
<div class="wrap" id="wp2pcs-admin-dashbord">
	<h2>WordPress同步到其他WordPress</h2>
  <div class="metabox-holder">
		<div class="postbox">
			<h3>远端设置</h3>
			<div class="inside" style="border-bottom:1px solid #CCC;margin:0;padding:8px 10px;">
			<form method="post" autocomplete="off">
				<!-- 以免自动填充 { -->
				<div style="width:1px;height:1px;float:right;overflow:hidden;">
					<input type="text" />
					<input type="password" />
				</div>
				<!-- } -->
				<table id="wp2wp-blogs-list-table">
					<thead>
						<tr>
							<th>远端博客名(不能重复)</th>
							<th>远端博客地址(一定要加http://)</th>
							<th>远端作者(登录名)</th>
							<th>远端密码(登录密码)</th>
							<th>远端分类名(远端类别)</th>
						</tr>
					</thead>
					<tbody>
					<?php
					$blogsync = get_option('blogsync');
					if(!empty($blogsync)){
						echo '<tr>
	
							<td><input type="text" name="name" value="'.$blogsync['name'].'" class="short-text" /></td>
							<td><input type="text" name="url" value="'.$blogsync['url'].'" class="regular-text" /></td>
							<td><input type="text" name="user" value="'.$blogsync['user'].'" class="short-text" /></td>
							<td><input type="password" name="pwd" value="'.$blogsync['pwd'].'" class="short-text" /></td>
							<td><input type="text" name="cat" value="'.$blogsync['cat'].'" class="short-text" /></td>
						</tr>';
					}
					else echo '<tr>	
						<td><input type="text" name="name" value="" class="short-text" /></td>
						<td><input type="text" name="url" value="" class="regular-text" /></td>
						<td><input type="text" name="user" value="" class="short-text" /></td>
						<td><input type="password" name="pwd" value="" class="short-text" /></td>
						<td><input type="text" name="cat" value="" class="short-text" /></td>
					</tr>';
					?>
					</tbody>
				</table>
				<p></p>
				<p>
					<input type="submit" value="更新" class="button-primary" />
				</p>
				<input type="hidden" name="action" value="blogsync_update_options" />
				<?php
					if(!empty($_GET['page']) && isset($_GET['page'])) $page = $_GET['page'];
				?>
				<input type="hidden" name="page" value="<?php esc_html_e($page); ?>" />
			</form>
			</div>
		</div>
	</div>
</div>

<?php
}

// 保存文章的时候就开始同步
add_action('save_post','blogsync_post');
function blogsync_post($post_id){


	if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
		return;
	if(defined('DOING_AJAX') && DOING_AJAX)
		return;
	if(false!==wp_is_post_revision($post_id))
		return;

	$get_post = get_post($post_id,ARRAY_A);
	if(!$get_post)return;
	if($get_post['post_type']!='post' || trim($get_post['post_content'])=='')return;

	$status = ($get_post['post_status']=='publish' ? true : false);// 同步到的文章的状态，远程编辑的时候，又可以使文章成为待审

	$content = array(
		'title' => $get_post['post_title'],
		'description' => $get_post['post_content'],
		'post_type' => $get_post['post_type'],
		'mt_excerpt' => $get_post['post_excerpt'],
		'mt_allow_comments' => 1,
		'mt_allow_pings' => 1,
		'wp_slug' => $get_post['post_name'],
		'post_status' => $get_post['post_status'],
		'custom_fields' => ''
	);

	$get_tags = blogsync_get_tags($post_id);

	if($get_tags){
		$content['mt_keywords'] = implode(',',$get_tags);
	}

	$is_edit = $get_post['post_modified']>$get_post['post_date'] ? true : false;
	$client_posts = get_post_meta($post_id,'blogsync_posted',true);
	if(!$client_posts || empty($client_posts)){
		$is_edit = false;
	}
	$blogsync_posted = array();
	$blogsync = get_option('blogsync');

	include_once(ABSPATH."wp-includes/class-IXR.php");
	if(!empty($blogsync)){
		$client_xmlrpc = trailingslashit($blogsync['url']).'xmlrpc.php';
		$client = new IXR_Client($client_xmlrpc);
		$client_user = $blogsync['user'];
		$client_pwd = $blogsync['pwd'];
		// 如果把这篇文章移到回收站，或转为隐私文章，这时将客户端的文章也删除
		if($get_post['post_status']=='trash' || $get_post['post_status']=='private'){
			$client_post_id = @$client_posts[$blogsync['name']];
			if(!empty($client_post_id)){
				$client_action = 'metaWeblog.deletePost';
				$client->query($client_action,array('',$client_post_id,$client_user,$client_pwd,$status));
			}
		}
		// 正常的修改或发布文章
		else{
			$content['categories'] = array($blogsync['cat']);
			$client_post_id = 0;
			$client_action = 'metaWeblog.newPost';
			if($is_edit){
				$client_post_id = @$client_posts[$blogsync['name']];
				if($client_post_id)$client_action = 'metaWeblog.editPost';
			}

			$is_success = $client->query($client_action,array($client_post_id,$client_user,$client_pwd,$content,$status));
			if($is_success){
				$client_posted_id = $client->message->params;
				$client_posted_id = $client_posted_id[0];
				$blogsync_posted[$blogsync['name']] = $client_posted_id;
			}
		}
	}

	// 更新这个文章，表明它在被发布的博客中的ID号
	if($is_success && !$is_edit){
		add_post_meta($post_id,'blogsync_posted',$blogsync_posted,true) || update_post_meta($post_id,'blogsync_posted',$blogsync_posted);
	}
}

// 获取文章的标签列表（标签名称）
function blogsync_get_tags($post_id){
	global $wpdb;
	// 获取标签对象
	$sql = sprintf('SELECT term_id FROM %s r LEFT JOIN %s t ON r.term_taxonomy_id = t.term_taxonomy_id WHERE t.taxonomy = "post_tag" and r.object_id = %d',$wpdb->term_relationships,$wpdb->term_taxonomy,$post_id);
	$tags = $wpdb->get_results($sql);
	if(empty($tags)){
		return array();
	}
	$tag_ids = array();
	foreach($tags as $obj){
		$tag_ids[] = $obj->term_id;
	}
	$tag_ids = implode(',',$tag_ids);
	$sql = sprintf('SELECT name FROM %s WHERE term_id IN (%s)',$wpdb->terms,$tag_ids);
	$results = $wpdb->get_results($sql);
	if(empty($results)){
		return array();
	}
	$tags = array();
	foreach($results as $obj){
		$tags[] = $obj->name;
	}
	return $tags;
}