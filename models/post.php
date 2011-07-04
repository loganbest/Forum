<?php
/** 
 * Forum - Post Model
 *
 * @author		Miles Johnson - http://milesj.me
 * @copyright	Copyright 2006-2010, Miles Johnson, Inc.
 * @license		http://opensource.org/licenses/mit-license.php - Licensed under The MIT License
 * @link		http://milesj.me/resources/script/forum-plugin
 */
 
class Post extends ForumAppModel {

	/**
	 * Belongs to.
	 *
	 * @access public
	 * @var array
	 */
	public $belongsTo = array(
		'Forum' => array(
			'className' 	=> 'Forum.Forum',
			'counterCache' 	=> true
		),
		'Topic' => array(
			'className'		=> 'Forum.Topic',
			'counterCache'	=> true
		),
		'User'
	);
	
	/**
	 * Validation.
	 *
	 * @access public
	 * @var array
	 */
	public $validate = array(
		'content' => 'notEmpty'
	);
	
	/**
	 * Validate and add a post.
	 *
	 * @access public
	 * @param array $data
	 * @return boolean|int
	 */
	public function addPost($data) {
		$this->set($data);
		
		// Validate
		if ($this->validates()) {
			$settings = Configure::read('Forum.settings');
			$isAdmin = ($this->Session->read('Forum.isAdmin') > 0);
			$posts = $this->Session->read('Forum.posts');

			if (($secondsLeft = $this->checkFlooding($posts, $settings['post_flood_interval'])) > 0 && !$isAdmin) {
				return $this->invalidate('content', 'You must wait '. $secondsLeft .' more second(s) till you can post a reply');
				
			} else if ($this->checkHourly($posts, $settings['posts_per_hour']) && !$isAdmin) {
				return $this->invalidate('content', 'You are only allowed to post '. $settings['topics_per_hour'] .' time(s) per hour');
				
			} else {
				$data['Post']['content'] = strip_tags($data['Post']['content']);
				$data['Post']['contentHtml'] = $data['Post']['content']; // DECODA HERE
				
				// Save Topic
				$this->create();
				$this->save($data, false, array('topic_id', 'user_id', 'userIP', 'content'));
				
				$topic_id = $data['Post']['topic_id'];
				$user_id = $data['Post']['user_id'];
				$post_id = $this->id;
				
				// Update legend
				$this->Topic->update($topic_id, array(
					'lastPost_id' => $post_id,
					'lastUser_id' => $user_id,
				));
				
				$topic = $this->Topic->find('first', array(
					'conditions' => array('Topic.id' => $topic_id),
					'fields' => array('Topic.forum_id'),
					'contain' => array(
						'Forum' => array(
							'fields' => array('Forum.id', 'Forum.forum_id'),
							'Parent'
						)
					)
				));
				
				// Get total posts for forum
				$totalPosts = $this->find('count', array(
					'conditions' => array('Topic.forum_id' => $topic['Topic']['forum_id']),
					'contain' => array('Topic.forum_id')
				));
				
				$this->Topic->Forum->update($topic['Topic']['forum_id'], array(
					'lastTopic_id' => $topic_id,
					'lastPost_id' => $post_id,
					'lastUser_id' => $user_id,
					'post_count' => $totalPosts
				));
				
				// Update parent forum as well
				if (isset($topic['Forum']['Parent']['id']) && $topic['Forum']['forum_id'] != 0) {
					$this->Topic->Forum->update($topic['Forum']['Parent']['id'], array(
						'lastTopic_id' => $topic_id,
						'lastPost_id' => $post_id,
						'lastUser_id' => $user_id,
					));	
				}
				
				return $post_id;
			}
		}
		
		return false;
	}
	
	/**
	 * Check the posting flood interval.
	 *
	 * @access public
	 * @param array $posts
	 * @return boolean
	 */
	public function checkFlooding($posts, $interval) {
		if (!empty($topics)) {
			$lastPost = array_slice($posts, -1, 1);
			$lastTime = $lastPost[0];
		}

		if (isset($lastTime)) {
			$timeLeft = time() - $lastTime;
			
			if ($timeLeft <= $interval) {
				return $interval - $timeLeft;
			}
		}
		
		return false;
	}
	
	/**
	 * Check the hourly posting.
	 *
	 * @access public
	 * @param array $posts
	 * @param int $max
	 * @return boolean
	 */
	public function checkHourly($posts, $max) {
		$pastHour = strtotime('-1 hour');
			
		if (!empty($posts)) {
			$count = 0;
			foreach ($posts as $id => $time) {
				if ($time >= $pastHour) {
					++$count;
				}
			}
			
			if ($count >= $max) {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Get the latest posts by a user.
	 *
	 * @access public
	 * @param int $user_id
	 * @param int $limit
	 * @return array
	 */
	public function getLatestByUser($user_id, $limit = 5) {
		return $this->find('all', array(
			'conditions' => array('Post.user_id' => $user_id),
			'order' => array('Post.created' => 'DESC'),
			'limit' => $limit,
			'contain' => array(
				'Topic' => array(
					'fields' => array('Topic.id', 'Topic.title', 'Topic.slug', 'Topic.user_id'),
					'User.id', 'User.username'
				)
			)
		));
	}
	
	/**
	 * Get all info for editing a post.
	 *
	 * @access public
	 * @param int $id
	 * @return array
	 */
	public function getPostForEdit($id) {
		return $this->find('first', array(
			'conditions' => array('Post.id' => $id),
			'contain' => array(
				'Topic' => array(
					'fields' => array('Topic.id', 'Topic.title', 'Topic.slug'),
					'Forum' => array(
						'fields' => array('Forum.id', 'Forum.title', 'Forum.slug'),
						'Parent'
					)
				)
			)
		));
	}
	
	/**
	 * Get a post for quoting.
	 *
	 * @access public
	 * @param int $id
	 * @return array
	 */
	public function getQuote($id) {
		return $this->find('first', array(
			'conditions' => array('Post.id' => $id),
			'fields' => array('Post.content', 'Post.created'),
			'contain' => array('User.username')
		));
	}
	
	/**
	 * Get the latest posts in a topic.
	 * 
	 * @access public
	 * @param int $topic_id
	 * @param int $imit
	 * @return array
	 */
	public function getTopicReview($topic_id, $limit = 10) {
		return $this->find('all', array(
			'conditions' => array('Post.topic_id' => $topic_id),
			'contain' => array('User.id', 'User.username', 'User.created'),
			'order' => array('Post.created' => 'DESC'),
			'limit' => $limit
		));
	}

	/**
	 * Get a list of IDs for determining paging.
	 *
	 * @access public
	 * @param int $topic_id
	 * @return array
	 */
	public function getIdsForPaging($topic_id) {
		return $this->find('list', array(
			'conditions' => array('Post.topic_id' => $topic_id),
			'order' => array('Post.id' => 'ASC')
		));
	}
	
	/**
	 * NEW
	 */
	
	/**
	 * Save the first post with a topic.
	 *
	 * @access public
	 * @param int $topic_id
	 * @param array $data
	 * @return int
	 */
	public function addFirstPost($topic_id, $data) {
		$post = array(
			'topic_id' => $topic_id,
			'forum_id' => $data['forum_id'],
			'user_id' => $data['user_id'],
			'userIP' => $data['userIP'],
			'content' => Sanitize::clean($data['content']),
			'contentHtml' => Sanitize::clean($data['content']) // DECODA HERE
		);
		
		$this->create();
		$this->save($post, false, array_keys($post));

		return $this->id;
	}

}
