<?php 

class Broadcast_LifterLMS_Plugin
	extends \threewp_broadcast\premium_pack\base
{
	
	public function _construct()
	{
		$this->add_action( 'threewp_broadcast_menu' );
		$this->add_action( 'threewp_broadcast_broadcasting_started' );
		$this->add_action( 'threewp_broadcast_broadcasting_after_switch_to_blog' );
		$this->add_action( 'threewp_broadcast_broadcasting_modify_post' );
		$this->add_action( 'threewp_broadcast_broadcasting_before_restore_current_blog' );
		$this->add_action( 'threewp_broadcast_broadcasting_finished' );
	}
	
	public function admin_tabs()
	{
		$tabs = $this->tabs()->default_tab( 'course_broadcast' );

		$tabs->tab( 'engagement_broadcast' )
			->callback_this( 'engagement_broadcast' )
			->heading( __( 'LifterLMS Broadcast', 'threewp_broadcast' ) )
			->name( __( 'Engajamentos', 'threewp_broadcast' ) );

		$tabs->tab( 'membership_broadcast' )
			->callback_this( 'membership_broadcast' )
			->heading( __( 'LifterLMS Broadcast', 'threewp_broadcast' ) )
			->name( __( 'Associações', 'threewp_broadcast' ) );

		$tabs->tab( 'course_broadcast' )
			->callback_this( 'course_broadcast' )
			->heading( __( 'LifterLMS Broadcast', 'threewp_broadcast' ) )
			->name( __( 'Cursos', 'threewp_broadcast' ) );

		echo $tabs->render();
	}
	
	public function threewp_broadcast_menu( $action )
	{
		$access = is_super_admin();
		$access = apply_filters( 'broadcast_lifterlms_menu_access', $access );
		if ( ! $access )
			return;

		$action->menu_page
			->submenu( 'broadcast_lifterlms' )
			->callback_this( 'admin_tabs' )
			->menu_title( 'LifterLMS' )
			->page_title( 'LifterLMS' );
	}

	public function course_broadcast()
	{
		$form = $this->form2();
		$form->css_class( 'plainview_form_auto_tabs' );
		$r = '';

		$form->markup( 'm_course_broadcast' )
			->p( __( 'Use o formulário abaixo para sincronizar um curso completo para outros blogs.', 'threewp_broadcast' ) );

		$fs = $form->fieldset( 'fs_courses' );

		$courses = get_posts( [
			'post_type' => 'course',
			'posts_per_page' => 1000,
			'orderby' => 'post_title',
			'order' => 'ASC',
		] );

		$options = [];
		foreach( $courses as $course )
			$options[ $course->ID ] = sprintf( '%s (%s)', $course->post_title, $course->ID );

		$courses_to_manipulate = $fs->select( 'courses_to_manipulate' )
			->description( __( 'Selecione os cursos que deseja sincronizar.', 'threewp_broadcast' ) )
			->label( __( 'Cursos', 'threewp_broadcast' ) )
			->size( 20 )
			->opts( $options )
			->multiple();
			
		$blogs_select = $this->add_blog_list_input( [
			// Blog selection input description
			'description' => __( 'Selecione um ou mais blogs para os quais o curso será sincronizado.', 'threewp_broadcast' ),
			'form' => $fs,
			// Blog selection input label
			'label' => __( 'Blogs', 'threewp_broadcast' ),
			'multiple' => true,
			'name' => 'blogs',
			'size' => 20,
			'required' => false,
		] );
	
		$manipulation = $fs->select( 'manipulation' )
			->description( __( 'O que fazer com os cursos selecionados. Isso vale para qualquer tipo de conteúdo vinculado aos cursos. Ex: aulas, quizzes, planos, produtos.', 'threewp_broadcast' ) )
			->label( __( 'Ação', 'threewp_broadcast' ) )
			->opt( 'broadcast', __( 'Sincronizar', 'threewp_broadcast' ) )
			->opt( 'delete', __( 'Deletar', 'threewp_broadcast' ) )
			->opt( 'find_unlinked_children', __( 'Encontre filhos desvinculados', 'threewp_broadcast' ) )
			->opt( 'restore', __( 'Restaurar', 'threewp_broadcast' ) )
			->opt( 'trash', __( 'Lixo', 'threewp_broadcast' ) )
			->opt( 'unlink', __( 'Desvincular', 'threewp_broadcast' ) );
			
		// Fieldset label
		$fs->legend();

		$copy_button = $fs->primary_button( 'copy' )
			->value( __( 'Iniciar processo', 'threewp_broadcast' ) );

		if ( $form->is_posting() )
		{
			set_time_limit(10000); //160min
			ignore_user_abort(true);

			if( function_exists( 'fastcgi_finish_request' ) ) {
				$message = sprintf( '<strong>O processo está ocorrendo automaticamente em segundo plano. Verifique o progresso na página de <a href="%s">Fila do Broadcast.</a></strong>', admin_url( 'admin.php?page=threewp_broadcast_queue' ) );
				
				$r = $this->info_message_box()->_( $message );
				$r .= $form->open_tag();
				$r .= $form->display_form_table();
				$r .= $form->close_tag();

				echo $r;
				
				fastcgi_finish_request();
			}
			
			$form->post();
			$form->use_post_values();

			$blogs = $blogs_select->get_post_value();
			$courses = $courses_to_manipulate->get_post_value();
			$manipulation = $manipulation->get_post_value();
			$messages = [];

			foreach( $courses as $course_id )
			{
				$post_ids =  $this->get_course_data_ids( $course_id, 'ids' );
				$api = ThreeWP_Broadcast()->api();
				
				foreach( $post_ids as $post_id )
				{
					$post = get_post( $post_id );
					$posts []= sprintf( '%s %s, %s', $post->ID, $post->post_title, $post->post_type );
				}
				$messages []= sprintf( __( 'Selected %s for course %s. Post IDs affected:<br/>%s', 'threewp_broadcast' ),
					$manipulation,
					$course_id,
					implode( '<br/>', $posts )
				);
				$this->debug( 'Course tool: %s for %s', $manipulation, implode( ', ', $post_ids ) );
				
				switch( $manipulation )
				{
					case 'broadcast':
						$this->broadcast_whole( $post_ids, $blogs );
					break;
					case 'delete':
						foreach( $post_ids as $post_id )
							wp_delete_post( $post_id, true );
					break;
					case 'find_unlinked_children':
						$this->find_unlinked_children_whole( $post_ids, $blogs );
					break;
					case 'restore':
						foreach( $post_ids as $post_id )
							wp_untrash_post( $post_id );
					break;
					case 'trash':
						foreach( $post_ids as $post_id )
							wp_trash_post( $post_id );
					break;
					case 'unlink':
						foreach( $post_ids as $post_id )
							$api->unlink( $post_id );
					break;
				}
			}

			$messages = implode( "\n", $messages );
			$r .= $this->info_message_box()->_( $messages );
		}

		$r .= $form->open_tag();
		$r .= $form->display_form_table();
		$r .= $form->close_tag();

		echo $r;
	}
	
	public function get_course_data_ids( $course_id, $return = '' )
	{
		if ( ! class_exists( 'LifterLMS' ) ) {
			return [];
		}

		if ( ! ( $course =  new LLMS_Course( $course_id ) ) ) {
			return [];
		}

		$data = array( 'id' => (array) $course_id, 
			'lessons'      =>  [], 
			'sections'     =>  [], 
			'quizzes'      =>  [],
			'questions'    =>  [],
			'certificates' =>  [],
			'plans'        =>  [],
			'product'      =>  [],
			'wc_product'   =>  [],
		);
		
		foreach( $course->get_lessons() as $lid => $lesson ) {
			
			$lid   =  $lesson->get( 'id' );
			$sid   =  $lesson->get_parent_section( 'ids' );
			$quiz  =  $lesson->get_quiz();

			$data['lessons'][]   = $lesson->get( 'id' );
			$data['sections'][]  = $lesson->get_parent_section();
			
			if( $quiz ) {
				$data['quizzes'][] = $quiz->post->ID;
				$data['questions'] = array_merge( $data['questions'], $quiz->questions()->get_questions( 'ids' ) );
			}
		}
		
		if( $product = $course->get_product() ) {
			$data['product'][] = $product->post->ID;
			
			if( !empty( $access_plans = $product->get_access_plans() ) ) {
				foreach( $access_plans as $access_plan ) {
					$data['plans'][] = $access_plan->post->ID;
					
					if( !empty( $access_plan->get( 'wc_pid' ) ) ) {
						$data['wc_product'][] = $access_plan->get( 'wc_pid' );
					}
				}
			}
		
		}
		
		if ( 'ids' === $return ) {
			$data = array_merge( 
				$data['id'],
				$data['wc_product'],
				$data['sections'], 
				$data['lessons'], 
				$data['quizzes'],
				$data['questions'],
				$data['plans']
			);
		}
		
		$data = array_unique( $data );
		$data = array_filter( $data );
		
		return $data;
	}

	public function get_lesson_data_ids( $lesson_id, $return = '' )
	{
		if ( ! class_exists( 'LifterLMS' ) ) {
			return [];
		}

		if ( ! ( $lesson =  new LLMS_Lesson( $lesson_id ) ) ) {
			return [];
		}

		$data = array( 'id' => (array) $lesson_id, 
			'quizzes'      =>  [],
			'questions'    =>  [],
		);
		
		$quiz  =  $lesson->get_quiz();

		if( $quiz ) {
			$data['quizzes'][] = $quiz->post->ID;
			$data['questions'] = $quiz->questions()->get_questions( 'ids' );
		}

		if ( 'ids' === $return ) {
			$data = array_merge( 
				//$data['id'],
				$data['quizzes'],
				$data['questions'],
			);
		}
		
		$data = array_unique( $data );
		$data = array_filter( $data );
		
		return $data;
	}

	public function membership_broadcast()
	{
		$form = $this->form2();
		$form->css_class( 'plainview_form_auto_tabs' );
		$r = '';

		$form->markup( 'm_membership_broadcast' )
			->p( __( 'Use o formulário abaixo para sincronizar uma associação para outros blogs.', 'threewp_broadcast' ) );

		$fs = $form->fieldset( 'fs_memberships' );

		$memberships = get_posts( [
			'post_type' => 'llms_membership',
			'posts_per_page' => 500,
			'orderby' => 'post_title',
			'order' => 'ASC',
		] );

		$options = [];
		foreach( $memberships as $membership )
			$options[ $membership->ID ] = sprintf( '%s (%s)', $membership->post_title, $membership->ID );

		$memberships_to_manipulate = $fs->select( 'memberships_to_manipulate' )
			->description( __( 'Selecione as associações que deseja sincronizar.', 'threewp_broadcast' ) )
			->label( __( 'Associações para sincronizar', 'threewp_broadcast' ) )
			->opts( $options )
			->multiple();
			
		$blogs_select = $this->add_blog_list_input( [
			// Blog selection input description
			'description' => __( 'Selecione um ou mais blogs para os quais a associação será sincronizado.', 'threewp_broadcast' ),
			'form' => $fs,
			// Blog selection input label
			'label' => __( 'Blogs', 'threewp_broadcast' ),
			'multiple' => true,
			'name' => 'blogs',
			'required' => false,
		] );

		$manipulation = $fs->select( 'manipulation' )
			->description( __( 'O que fazer com as associações selecionados. Isso vale para qualquer tipo de conteúdo vinculado. Ex: planos e produtos.', 'threewp_broadcast' ) )
			->label( __( 'Ação', 'threewp_broadcast' ) )
			->opt( 'broadcast', __( 'Sincronizar', 'threewp_broadcast' ) )
			->opt( 'delete', __( 'Deletar', 'threewp_broadcast' ) )
			->opt( 'find_unlinked_children', __( 'Encontre filhos desvinculados', 'threewp_broadcast' ) )
			->opt( 'restore', __( 'Restaurar', 'threewp_broadcast' ) )
			->opt( 'trash', __( 'Lixo', 'threewp_broadcast' ) )
			->opt( 'unlink', __( 'Desvincular', 'threewp_broadcast' ) );
			
		// Fieldset label
		$fs->legend();

		$copy_button = $fs->primary_button( 'copy' )
			->value( __( 'Iniciar processo', 'threewp_broadcast' ) );

		if ( $form->is_posting() )
		{
			set_time_limit(10000); //160min
			ignore_user_abort(true);

			if( function_exists( 'fastcgi_finish_request' ) ) {
				$message = sprintf( '<strong>O processo está ocorrendo automaticamente em segundo plano. Verifique o progresso na página de <a href="%s">Fila do Broadcast.</a></strong>', admin_url( 'admin.php?page=threewp_broadcast_queue' ) );
				
				$r = $this->info_message_box()->_( $message );
				$r .= $form->open_tag();
				$r .= $form->display_form_table();
				$r .= $form->close_tag();

				echo $r;
				
				fastcgi_finish_request();
			}
			
			$form->post();
			$form->use_post_values();

			$blogs = $blogs_select->get_post_value();
			$memberships = $memberships_to_manipulate->get_post_value();
			$manipulation = $manipulation->get_post_value();
			$messages = [];

			foreach( $memberships as $membership_id )
			{
				$post_ids = $this->get_membership_data_ids( $membership_id, 'ids' );
				$api = ThreeWP_Broadcast()->api();

				foreach( $post_ids as $post_id )
				{
					$post = get_post( $post_id );
					$posts []= sprintf( '%s %s, %s', $post->ID, $post->post_title, $post->post_type );
				}
				$messages []= sprintf( __( 'Selected %s for membership %s. Post IDs affected:<br/>%s', 'threewp_broadcast' ),
					$manipulation,
					$membership_id,
					implode( '<br/>', $posts )
				);
				$this->debug( 'Membership tool: %s for %s', $manipulation, implode( ', ', $post_ids ) );
				switch( $manipulation )
				{
					case 'broadcast':
						$this->broadcast_whole( $post_ids, $blogs );
					break;
					case 'delete':
						foreach( $post_ids as $post_id )
							wp_delete_post( $post_id, true );
					break;
					case 'find_unlinked_children':
						$this->find_unlinked_children_whole( $post_ids, $blogs );
					break;
					case 'restore':
						foreach( $post_ids as $post_id )
							wp_untrash_post( $post_id );
					break;
					case 'trash':
						foreach( $post_ids as $post_id )
							wp_trash_post( $post_id );
					break;
					case 'unlink':
						foreach( $post_ids as $post_id )
							$api->unlink( $post_id );
					break;
				}
			}

			$messages = implode( "\n", $messages );
			$r .= $this->info_message_box()->_( $messages );
		}

		$r .= $form->open_tag();
		$r .= $form->display_form_table();
		$r .= $form->close_tag();

		echo $r;
	}

	public function get_membership_data_ids( $membership_id, $return = '' )
	{
		if ( ! class_exists( 'LifterLMS' ) ) {
			return [];
		}

		if ( ! ( $membership =  new LLMS_Membership( $membership_id ) ) ) {
			return [];
		}

		$data = array( 'id' => (array) $membership_id,
			'courses'      =>  [],
			'plans'        =>  [],
			'product'      =>  [],
			'wc_product'   =>  [],
		);

		if( $membership->get_auto_enroll_courses() ) {
			$data['courses'][] = $membership->get_auto_enroll_courses();
		}
		
		if( $product = $membership->get_product() ) {
			$data['product'][] = $product->post->ID;
			
			if( !empty( $access_plans = $product->get_access_plans() ) ) {
				foreach( $access_plans as $access_plan ) {
					$data['plans'][] = $access_plan->post->ID;
					
					if( !empty( $access_plan->get( 'wc_pid' ) ) ) {
						$data['wc_product'][] = $access_plan->get( 'wc_pid' );
					}
				}
			}
		
		}

		if ( 'ids' === $return ) {
			$data = array_merge( 
				$data['id'],
				//$data['courses'],
				$data['plans'], 
				$data['product'], 
				$data['wc_product']
			);
		}
		
		$data = array_unique( $data );
		$data = array_filter( $data );

		return $data;
	}

	public function broadcast_whole( $post_ids, $blogs )
	{
		$this->debug( 'Broadcasting whole posts: %s', $post_ids );

		foreach( $post_ids as $index => $post_id )
		{
			$this->debug( 'Broadcasting post part %d on blog %s.', $post_id, get_current_blog_id() );
			// This is a workaround for the queue. As of 2019-02-13 there is a "bug" that makes the queue handle all instances of a post at the time.
			// Ex: This makes it impossible to correctly broadcast a course: course, then lessons + topics etc, then the course again.
			// Only the "first" course is high priority.
			if ( $index < 1 )
			{
				ThreeWP_Broadcast()->api()->broadcast_children( $post_id, $blogs );
				$this->debug( 'Finished broadcasting %d on blog %d.', $post_id, get_current_blog_id() );
				continue;
			}

			ThreeWP_Broadcast()->api()->low_priority()->broadcast_children( $post_id, $blogs );
			$this->debug( 'Finished broadcasting post part %d on blog %d.', $post_id, get_current_blog_id() );
		}
	}

	public function find_unlinked_children_whole( $post_ids, $blogs )
	{
		$api = ThreeWP_Broadcast()->api();
		$this->debug( 'find unlinked children whole posts: %s on blogs %s', $post_ids, $blogs );
		
		foreach( $post_ids as $index => $post_id )
		{
			if ( $index > 0 ) {
				$api->high_priority = false;
			}

			$this->debug( 'Broadcasting post part %d.', $post_id );
			$api->find_unlinked_children( $post_id, $blogs );
			$this->debug( 'Finished find unlinked children %d.', $post_id );
		}
	}

	public function get_engagement_data_ids( $engagement_id, $return = '' )
	{
		if ( ! class_exists( 'LifterLMS' ) ) {
			return [];
		}

		if ( ! ( $engagement = get_post( $engagement_id ) ) ) {
			return [];
		}

		$data = array( 'id' => $engagement_id, 
			'engagement'      	  =>  get_post_meta( $engagement_id, '_llms_engagement', true ), 
			'engagement_trigger'  =>  get_post_meta( $engagement_id, '_llms_engagement_trigger_post', true ), 
		);

		return $data;
	}

	public function engagement_broadcast()
	{
		$form = $this->form2();
		$form->css_class( 'plainview_form_auto_tabs' );
		$r = '';

		$form->markup( 'm_engagement_broadcast' )
			->p( __( 'Use o formulário abaixo para sincronizar um engajamento para outros blogs.', 'threewp_broadcast' ) );

		$fs = $form->fieldset( 'fs_engagements' );

		$engagements = get_posts( [
			'post_type' => 'llms_engagement',
			'posts_per_page' => 500,
			'orderby' => 'post_title',
			'order' => 'ASC',
		] );

		$options = [];
		foreach( $engagements as $engagement )
			$options[ $engagement->ID ] = sprintf( '%s (%s)', $engagement->post_title, $engagement->ID );

		$engagements_to_manipulate = $fs->select( 'engagements_to_manipulate' )
			->description( __( 'Selecione os engajamentos que deseja sincronizar.', 'threewp_broadcast' ) )
			->label( __( 'Engajamentos para sincronizar', 'threewp_broadcast' ) )
			->opts( $options )
			->multiple();
			
		$blogs_select = $this->add_blog_list_input( [
			// Blog selection input description
			'description' => __( 'Selecione um ou mais blogs para os quais a associação será sincronizado.', 'threewp_broadcast' ),
			'form' => $fs,
			// Blog selection input label
			'label' => __( 'Blogs', 'threewp_broadcast' ),
			'multiple' => true,
			'name' => 'blogs',
			'required' => false,
		] );

		$manipulation = $fs->select( 'manipulation' )
			->description( __( 'O que fazer com os engajamentos selecionados. Isso vale para qualquer tipo de conteúdo vinculado. Ex: certificados.', 'threewp_broadcast' ) )
			->label( __( 'Ação', 'threewp_broadcast' ) )
			->opt( 'broadcast', __( 'Sincronizar', 'threewp_broadcast' ) )
			->opt( 'delete', __( 'Deletar', 'threewp_broadcast' ) )
			->opt( 'find_unlinked_children', __( 'Encontre filhos desvinculados', 'threewp_broadcast' ) )
			->opt( 'restore', __( 'Restaurar', 'threewp_broadcast' ) )
			->opt( 'trash', __( 'Lixo', 'threewp_broadcast' ) )
			->opt( 'unlink', __( 'Desvincular', 'threewp_broadcast' ) );
			
		// Fieldset label
		$fs->legend();

		$copy_button = $fs->primary_button( 'copy' )
			->value( __( 'Iniciar processo', 'threewp_broadcast' ) );

		if ( $form->is_posting() )
		{
			set_time_limit(10000); //160min
			ignore_user_abort(true);

			if( function_exists( 'fastcgi_finish_request' ) ) {
				$message = sprintf( '<strong>O processo está ocorrendo automaticamente em segundo plano. Verifique o progresso na página de <a href="%s">Fila do Broadcast.</a></strong>', admin_url( 'admin.php?page=threewp_broadcast_queue' ) );
				
				$r = $this->info_message_box()->_( $message );
				$r .= $form->open_tag();
				$r .= $form->display_form_table();
				$r .= $form->close_tag();

				echo $r;
				
				fastcgi_finish_request();
			}
			
			$form->post();
			$form->use_post_values();

			$blogs = $blogs_select->get_post_value();
			$engagements = $engagements_to_manipulate->get_post_value();
			$manipulation = $manipulation->get_post_value();
			$messages = [];

			foreach( $engagements as $engagement_id )
			{
				$post_ids = $this->get_engagement_data_ids( $engagement_id );
				$api = ThreeWP_Broadcast()->api();
				
				foreach( $post_ids as $post_id )
				{
					$post = get_post( $post_id );
					$posts []= sprintf( '%s %s, %s', $post->ID, $post->post_title, $post->post_type );
				}
				$messages []= sprintf( __( 'Selected %s for engagement %s. Post IDs affected:<br/>%s', 'threewp_broadcast' ),
					$manipulation,
					$engagement_id,
					implode( '<br/>', $posts )
				);
				$this->debug( 'engagement tool: %s for %s', $manipulation, implode( ', ', $post_ids ) );

				switch( $manipulation )
				{
					case 'broadcast':
						$this->broadcast_whole( $post_ids, $blogs );
					break;
					case 'delete':
						foreach( $post_ids as $post_id )
							wp_delete_post( $post_id, true );
					break;
					case 'find_unlinked_children':
						$this->find_unlinked_children_whole( $post_ids, $blogs );
					break;
					case 'restore':
						foreach( $post_ids as $post_id )
							wp_untrash_post( $post_id );
					break;
					case 'trash':
						foreach( $post_ids as $post_id )
							wp_trash_post( $post_id );
					break;
					case 'unlink':
						foreach( $post_ids as $post_id )
							$api->unlink( $post_id );
					break;
				}
			}

			$messages = implode( "\n", $messages );
			$r .= $this->info_message_box()->_( $messages );
		}

		$r .= $form->open_tag();
		$r .= $form->display_form_table();
		$r .= $form->close_tag();

		echo $r;
	}
	
	public function threewp_broadcast_broadcasting_started( $action )
	{
		$bcd = $action->broadcasting_data;		// Convenience
		
		// An array of custom field names that should be modified. The array should be the same for preparsing and parsing.
		$custom_fields = [
			'_llms_parent_course' => [],
			'_llms_parent_section' => [],
			'_llms_lesson_id' => [],
			'_llms_prerequisite' => [],
			'_llms_parent_id' => [],
			'_llms_quiz' => [],
			'_llms_quiz_id' => [],
			'_llms_certificate_linked_course' => [],
			'_llms_certificate_lesson_trigger' => [],
			'_llms_engagement_id' => [],
			'_llms_engagement' => [],
			'_llms_engagement_trigger_post' => [],
			'_llms_product_id' => [],
			'_llms_wc_pid' => [],
			'_llms_plan_id' => [],
			'_llms_plan_wc_pid' => [],
			'_llms_redirect_page_id' => [],
			'_llms_sales_page_content_page_id' => [],
			'_llms_auto_enroll' => [],
			'_cover_id' => ['attachment', 'protect'],
			'_llms_certificate_image' => ['attachment', 'protect' => []],
			'_llms_certificate_image_verse' => ['attachment', 'protect' => []],
		];

		foreach( $custom_fields as $custom_field => $data )
		{
			
			ThreeWP_Broadcast()->debug( 'Preparsing custom field %s', $custom_field );
			$preparse_action = new \threewp_broadcast\actions\preparse_content();
			$preparse_action->broadcasting_data = $action->broadcasting_data;
			$preparse_action->broadcasting_data->upload_dir['url'] = '####';
			$preparse_action->content = $bcd->custom_fields()->get_single( $custom_field );
			$preparse_action->id = 'custom_field_' . $custom_field;
			$preparse_action->execute();

			if( in_array( 'attachment', $data ) ) {
				$bcd->add_attachment( $preparse_action->content );
			}
		}
		
		// Ensures the image and highlighting is not transferred
		if( in_array( $bcd->post->post_type, ['llms_certificate', 'course'] ) ) 
		{
			$bcd->custom_fields->blacklist[] = '_thumbnail_id';
		}

		if( $bcd->post->post_type == 'llms_question' ) 
		{		
			foreach( $bcd->custom_fields() as $meta_key => $meta_value ) {

				if ( substr( $meta_key, 0, 13 ) === '_llms_choice_' ) {				
					$meta_value = unserialize( $meta_value[0] );
					if( 'image' == $meta_value['choice_type'] && !empty( $meta_value['choice']['id'] ) ) {
						$bcd->add_attachment( $meta_value['choice']['id'] );
					}
				}
			}
		}

		if( $bcd->post->post_type == 'course' ) 
		{		
			$linking = ThreeWP_Broadcast()->api()->linking( $bcd->broadcast_data->blog_id, $bcd->broadcast_data->post_id );
			
			if ( ! empty( $linking->children() ) ) {
				if ( isset( $bcd->parent_post_taxonomies[ 'course_cat' ] ) ) {
					//unset( $bcd->parent_post_taxonomies[ 'course_cat' ] );
				}
				if ( isset( $bcd->parent_post_taxonomies[ 'course_difficulty' ] ) ) {
					//unset( $bcd->parent_post_taxonomies[ 'course_difficulty' ] );
				}
			}
		}
		
		//add protect atribute
		$pcp = ThreeWP_Broadcast()->collection();
		$bcd->protect_child_properties = $pcp;		
		$pcp->blogs = ThreeWP_Broadcast()->collection();
	}

	public function threewp_broadcast_broadcasting_after_switch_to_blog( $action )
	{
		$bcd = $action->broadcasting_data;		// Convenience
		
		if( in_array( $bcd->post->post_type, ['llms_certificate', 'course'] ) )
		{
			$pcp = $bcd->protect_child_properties;
			
			$blog_id = get_current_blog_id();

			$child_post_id = $bcd->broadcast_data->get_linked_child_on_this_blog();
			
			$child_post = get_post( $child_post_id );

			$pcp->blogs->collection( $blog_id )->set( 'old_meta', get_post_meta( $child_post_id ) );
			
			$pcp->blogs->collection( $blog_id )->set( 'old_post', $child_post );
		}
	}

	public function threewp_broadcast_broadcasting_modify_post( $action )
	{
		$bcd = $action->broadcasting_data;		// Convenience

		if( $bcd->post->post_type == 'course' ) 
		{		
			$linking = ThreeWP_Broadcast()->api()->linking( $bcd->broadcast_data->blog_id, $bcd->broadcast_data->post_id );
			$children = $linking->children();
			
			if ( ! empty( $linking->children() ) ) {
				$bcd->modified_post->menu_order = get_post_meta( $bcd->modified_post->ID, '_llms_priority', true );
			}
		}
		
	}

	public function threewp_broadcast_broadcasting_before_restore_current_blog( $action )
	{
		$bcd = $action->broadcasting_data;		// Convenience

		// An array of custom field names that should be modified. The array should be the same for preparsing and parsing.
		$custom_fields = [
			'_llms_parent_course' => [],
			'_llms_parent_section' => [],
			'_llms_lesson_id' => [],
			'_llms_prerequisite' => [],
			'_llms_parent_id' => [],
			'_llms_quiz' => [],
			'_llms_quiz_id' => [],
			'_llms_certificate_linked_course' => [],
			'_llms_certificate_lesson_trigger' => [],
			'_llms_engagement_id' => [],
			'_llms_engagement' => [],
			'_llms_engagement_trigger_post' => [],
			'_llms_product_id' => [],
			'_llms_wc_pid' => [],
			'_llms_plan_id' => [],
			'_llms_plan_wc_pid' => [],
			'_llms_redirect_page_id' => [],
			'_llms_sales_page_content_page_id' => [],
			'_llms_auto_enroll' => [],
			'_cover_id' => ['attachment', 'protect'],
			'_llms_certificate_image' => ['attachment', 'protect'],
			'_llms_certificate_image_verse' => ['attachment', 'protect'],
		];
		
		if( $bcd->post->post_type == 'llms_question' ) 
		{		
			foreach( $bcd->custom_fields() as $meta_key => $meta_value ) {
				
				if ( substr( $meta_key, 0, 13 ) === '_llms_choice_' ) {
					$custom_fields[$meta_key] = [];
				}

			}
		}
		
		foreach( $custom_fields as $custom_field => $data )
		{
			$api = ThreeWP_Broadcast()->api();
			ThreeWP_Broadcast()->debug( 'Parsing custom field %s', $custom_field );
			$parse_action = new \threewp_broadcast\actions\parse_content();
			$parse_action->broadcasting_data = $action->broadcasting_data;
			$parse_action->content = $bcd->custom_fields()->get_single( $custom_field );
			$parse_action->id = 'custom_field_' . $custom_field;
			$parse_action->execute();
			
			if( !empty( $parse_action->content ) ) {

				if( in_array( 'attachment', $data ) ) {
					$new_value = $action->broadcasting_data->copied_attachments()->get( $parse_action->content );
					
					if( in_array( 'protect', $data ) && $bcd->new_child_created !== true ) {
						continue;
					}
					
					if( $new_value ) {
						$bcd->custom_fields()->child_fields()->update_meta( $custom_field, $new_value );
						ThreeWP_Broadcast()->debug( 'New attachment value for custom field %s is %s', $custom_field, $new_value );
					}
				}
				elseif( '_llms_auto_enroll' == $custom_field  ) {
					$new_value = array();
					
					foreach( maybe_unserialize( $parse_action->content ) as $course_id ) {
						$linking = ThreeWP_Broadcast()->api()->linking( 1, $course_id );
						$children = $linking->children();
						
						if( isset( $children[ get_current_blog_id() ] ) ) { 
							$new_value[] = $children[ get_current_blog_id() ];
						}
					}
					
					$bcd->custom_fields()->child_fields()->update_meta( $custom_field, $new_value );
					ThreeWP_Broadcast()->debug( 'New value for custom field %s is %s', $custom_field, $new_value );
					
				} elseif( '_llms_choice_' === substr( $custom_field, 0, 13 ) ) {
					
					//https://stackoverflow.com/questions/10152904/how-to-repair-a-serialized-string-which-has-been-corrupted-by-an-incorrect-byte
					$fix_new_value = preg_replace_callback ( '!s:(\d+):"(.*?)";!', function($match) {      
						return ($match[1] == strlen($match[2])) ? $match[0] : 's:' . strlen($match[2]) . ':"' . $match[2] . '";';
					}, $parse_action->content );

					$new_value = maybe_unserialize( $fix_new_value );
					$new_value['question_id'] = $bcd->new_post( 'ID' );
					
					if( 'image' == $new_value['choice_type'] && !empty( $new_value['choice']['id'] ) ) {
						$new_value['choice']['id'] = $action->broadcasting_data->copied_attachments()->get( $new_value['choice']['id'] );
						$new_value['choice']['src'] = wp_get_attachment_url( $new_value['choice']['id'] );
					}
					
					$bcd->custom_fields()->child_fields()->update_meta( $custom_field, $new_value );
					ThreeWP_Broadcast()->debug( 'New value for custom field %s is %s', $custom_field, $new_value );
				} else {
					$linking = ThreeWP_Broadcast()->api()->linking( 1, $parse_action->content );
					$children = $linking->children();

					if( isset( $children[ get_current_blog_id() ] ) ) {
						$new_value = $children[ get_current_blog_id() ];
						$bcd->custom_fields()->child_fields()->update_meta( $custom_field, $new_value );
						ThreeWP_Broadcast()->debug( 'New value for custom field %s is %s', $custom_field, $new_value );
						
						//Update quiz id in lesson.
						//The quiz is broadcast after the lesson, so we need to retrieve the quiz ID and update the lesson.
 						if( $bcd->post->post_type == 'llms_quiz' ) 
						{			
							$lesson_id = get_post_meta( $bcd->new_post->ID, '_llms_lesson_id', true );
							$this->debug( 'Update quiz id in lesson %s / %s on blog %s', $lesson_id, $bcd->new_post->ID, $bcd->current_child_blog_id );
							update_post_meta( $lesson_id, '_llms_quiz', $bcd->new_post->ID );
						}
						
					}
				}

			}		
		}

		//protect thumbnail for certificates
		if( in_array( $bcd->post->post_type, ['llms_certificate', 'course'] ) ) 
		{		
			if( $bcd->new_child_created !== true ) {
				$blog_id = get_current_blog_id();				
				$cf = $bcd->custom_fields()->child_fields();
				$pcp = $bcd->protect_child_properties;
				
				$meta_key = '_thumbnail_id';
				$old_meta = $pcp->blogs->get( $blog_id )->get( 'old_meta' );
				
				if ( isset( $old_meta[ $meta_key ] ) )
				{
					$meta_value = reset( $old_meta[ $meta_key ] );
					$cf->update_meta( $meta_key, $meta_value );
					$this->debug( 'Restored old featured image (thumbnail) %s: %s', $meta_key, $meta_value );
				}			
			}				
		}
	}

	public function threewp_broadcast_broadcasting_finished( $action )
	{
		$bcd = $action->broadcasting_data;
		
		/*  $this->debug( 'Broadcasting Finished Debug' );
		$this->debug( 'Blog: %s', get_current_blog_id() );
		$this->debug( 'Child Blog: %s', $bcd->current_child_blog_id );
		$this->debug( 'Parent Post: %s', $bcd->post->ID );
		$this->debug( 'Parent Post Type: %s', $bcd->post->post_type );
		$this->debug( 'Child Post: %s', $bcd->new_post->ID );
		$this->debug( 'Child Post Type: %s', $bcd->new_post->post_type );*/
		
		//Broadcasting all the content of a lesson when the post is updated in the wordpress dashboard.
		if( $bcd->post->post_type == 'lesson' && isset( $bcd->_POST['action'] ) && $bcd->_POST['action'] == 'editpost' ) { 
			$lesson_id = $bcd->post->ID;
			$post_ids = $this->get_lesson_data_ids( $lesson_id, 'ids' );
			$this->broadcast_whole( $post_ids, [ $bcd->current_child_blog_id ] );
			$this->debug( 'Broadcast one lesson content %s on blog %d.', $post_ids, get_current_blog_id() );
		}
	}
}

new Broadcast_LifterLMS_Plugin();