<?php
/**
*
* @package phpBB Extension - Reply to see content
* @copyright (c) 2020 dmzx - https://www.dmzx-web.net
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace dmzx\replytoseecontent\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use phpbb\content_visibility;
use phpbb\db\driver\driver_interface as db_interface;
use phpbb\request\request_interface;
use phpbb\template\template;
use phpbb\auth\auth;
use phpbb\user;

class listener implements EventSubscriberInterface
{
	/* @var content_visibility */
	protected $content_visibility;

	/* @var db_interface */
	protected $db;

	/** @var request_interface */
	protected $request;

	/** @var template */
	protected $template;

	/** @var auth */
	protected $auth;

	/** @var user */
	protected $user;

	protected $b_seecontent = false;

	private $b_topic_replied = false;

	/**
	* Constructor for listener
	*
	* @param content_visibility		$content_visibility
	* @param db_interface			$db
	* @param request_interface		$request
	* @param template				$template
	* @param auth					$auth
	* @param user					$user
	*
	*/
	public function __construct(
		content_visibility $content_visibility,
		db_interface $db,
		request_interface $request,
		template $template,
		auth $auth,
		user $user
	)
	{
		$this->content_visibility 	= $content_visibility;
		$this->db 					= $db;
		$this->request 				= $request;
		$this->template 			= $template;
		$this->auth 				= $auth;
		$this->user 				= $user;
	}

	static public function getSubscribedEvents()
	{
		return [
			'core.user_setup'								=> 'load_language_on_setup',
			'core.acp_manage_forums_request_data'			=> 'acp_manage_forums_request_data',
			'core.acp_manage_forums_initialise_data'		=> 'acp_manage_forums_initialise_data',
			'core.acp_manage_forums_display_form'			=> 'acp_manage_forums_display_form',
			'core.viewtopic_assign_template_vars_before'	=> 'viewtopic_assign_template_vars_before',
			'core.modify_posting_parameters'				=> 'modify_posting_parameters',
			'core.viewtopic_get_post_data'					=> 'viewtopic_get_post_data',
			'core.viewtopic_modify_post_row'				=> 'viewtopic_modify_post_row',
			'core.search_modify_rowset'						=> 'search_modify_rowset',
			'core.search_modify_param_before'				=> 'search_modify_param_before',
			'core.ucp_pm_compose_quotepost_query_after'		=> 'ucp_pm_compose_quotepost_query_after',
			'core.topic_review_modify_row'					=> 'topic_review_modify_row',
		];
	}

	public function load_language_on_setup($event)
	{
		$lang_set_ext = $event['lang_set_ext'];
		$lang_set_ext[] = array(
			'ext_name' => 'dmzx/replytoseecontent',
			'lang_set' => 'common',
		);
		$event['lang_set_ext'] = $lang_set_ext;
	}

	public function acp_manage_forums_request_data($event)
	{
		$forum_data = $event['forum_data'];
		$forum_data['replytoseecontent_enable'] = $this->request->variable('replytoseecontent_enable', 0);
		$event['forum_data'] = $forum_data;
	}

	public function acp_manage_forums_initialise_data($event)
	{
		if ($event['action'] == 'add')
		{
			$forum_data = $event['forum_data'];
			$forum_data['replytoseecontent_enable'] = (int) 0;
			$event['forum_data'] = $forum_data;
		}
	}

	public function acp_manage_forums_display_form($event)
	{
		$template_data = $event['template_data'];
		$template_data['S_REPLYTOSEECONTENT_ENABLE'] = $event['forum_data']['replytoseecontent_enable'];
		$event['template_data'] = $template_data;
	}

	public function viewtopic_assign_template_vars_before($event)
	{
		$topic_data = $event['topic_data'];
		$post_id = $event['post_id'];
		$start = $event['start'];
		$total_posts = $event['total_posts'];
		$topic_id = $event['topic_id'];

		$s_replytoseecontent = $this->check_topic_id($topic_id);

		if ($s_replytoseecontent)
		{
			$topic_data['prev_posts'] = $start = 0;
			$total_posts = 1;
			$post_id == $topic_data['topic_first_post_id'];
		}

		$event['topic_id'] = $topic_id;
		$event['total_posts'] = $total_posts;
		$event['topic_data'] = $topic_data;
		$event['post_id'] = $post_id;
		$event['start'] = $start;
	}

	public function modify_posting_parameters($event)
	{
		if ($event['mode'] == 'post')
		{
			$this->b_seecontent = true;

			return;
		}

		if ($event['post_id'] != 0)
		{
			$this->check_post_id($event['post_id']);
		}
		else if($event['topic_id'] != 0)
		{
			$this->check_topic_id($event['topic_id']);
		}
	}

	public function viewtopic_get_post_data($event)
	{
		$topic_data = $event['topic_data'];
		$sql_ary 	= $event['sql_ary'];
		$post_list 	= $event['post_list'];
		$topic_id 	= $event['topic_id'];

		$s_replytoseecontent = (!empty($topic_data['replytoseecontent_enable']) && $this->check_topic_id($topic_id));

		if ($s_replytoseecontent)
		{
			$post_list = [(int) $topic_data['topic_first_post_id']];
			$sql_ary['WHERE'] = $this->db->sql_in_set('p.post_id', $post_list) . ' AND u.user_id = p.poster_id';

			$topic_replies = $this->content_visibility->get_count('topic_posts', $topic_data, $event['forum_id']) - 1;

	 		$this->template->assign_vars([
				'S_REPLYTOSEECONTENT'			=> !$this->b_topic_replied && !$this->b_seecontent,
				'REPLYTOSEECONTENT_MESSAGE'		=> $topic_replies ? $this->user->lang('REPLYTOSEECONTENT_REPLY', $topic_replies) : '',
			]);
		}
		$event['post_list'] = $post_list;
		$event['sql_ary'] = $sql_ary;
	}

 	public function viewtopic_modify_post_row($event)
	{
		$post_data = $event['row'];
		$post_row	= $event['post_row'];

		$forum_ids = $this->seecontent_forums();

		if (!$this->unhide_in_post($post_data['post_id']) && (in_array($event['topic_data']['forum_id'], $forum_ids)))
		{
			$post_row = array_merge($post_row, [
				'U_QUOTE' => false
			]);
		}
		$event['post_row'] = $post_row;
	}

	public function search_modify_rowset($event)
	{
		$a_topicIds = [];

		$rowset = $event['rowset'];

		foreach($rowset as $row)
		{
			$a_topicIds[$row['topic_id']] = 1;
		}

		$a_topicIds = array_keys($a_topicIds);

		$a_topic_replied = $this->check_topic_ids($a_topicIds);

		foreach($rowset as $key => $row)
		{
			$topic_id = $row['topic_id'];

			$forum_ids = $this->seecontent_forums();

			if ($a_topic_replied[$topic_id] == $key['topic_id'] && (in_array($row['forum_id'], $forum_ids)))
			{
				$rowset[$key]['post_text'] = str_replace($row['post_text'], '', $this->user->lang('REPLYTOSEECONTENT_TEXT'));
			}
		}
		$event['rowset'] = $rowset;
	}

	public function search_modify_param_before($event)
	{
		if (!$this->user->data['is_registered'])
		{
			$ex_fid_array = $event['ex_fid_ary'];
			$forum_ids = $this->seecontent_forums();
			$ex_fid_array = array_unique(array_merge($ex_fid_array, $forum_ids));
			$event['ex_fid_ary'] = $ex_fid_array;
		}
	}

	public function ucp_pm_compose_quotepost_query_after($event)
	{
		$post_id = $event['msg_id'];

		$this->check_post_id($post_id);

		$post = $event['post'];
		$bbcode_uid = $post['bbcode_uid'];

		if (!$this->unhide_in_post($post_id))
		{
			$post['message_text'] = $this->user->lang('REPLYTOSEECONTENT_TEXT');
		}
		$event['post'] = $post;
	}

	public function topic_review_modify_row($event)
	{
		$forum_id = (int) $event['forum_id'];

		if (!$this->unhide_in_post($event['row']['post_id']) && (in_array($forum_id, $this->seecontent_forums())))
		{
			$post_row = $event['post_row'];

			$post_row = array_merge($post_row, [
				'POSTER_QUOTE' 	=> false,
				'MESSAGE' 		=> $this->user->lang('REPLYTOSEECONTENT_TEXT'),
			]);
			$event['post_row'] = $post_row;
		}
	}

	private function seecontent_forums()
	{
		$forum_ids = [];

		$sql = 'SELECT forum_id
			FROM ' . FORUMS_TABLE . '
			WHERE replytoseecontent_enable = ' . true;
		$result = $this->db->sql_query($sql);
		$forums = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		foreach ($forums as $forum)
		{
			foreach ($forum as $id)
			{
				$forum_ids[] = $id;
			}
		}
		return $forum_ids;
	}

	private function unhide_in_post($post_id)
	{
		return	($this->b_topic_replied) || $this->b_seecontent;
	}

	private function check_topic_ids($topic_ids)
	{
		$a_topic_replied = [];

		foreach($topic_ids as $topic_id)
		{
			$a_topic_replied[$topic_id] = false;
		}

		$sql = 'SELECT poster_id, topic_id
			FROM ' . POSTS_TABLE . '
			WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids) . '
			AND poster_id = ' . (int) $this->user->data['user_id'];
		$result = $this->db->sql_query($sql);

		while($row = $this->db->sql_fetchrow($result))
		{
			$a_topic_replied[$row['topic_id']] = true;
		}
		$this->db->sql_freeresult($result);

		$forum_ids = [];
		$sql = 'SELECT topic_id, forum_id
			FROM ' . TOPICS_TABLE . '
			WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids);
		$result = $this->db->sql_query($sql);

		while($row = $this->db->sql_fetchrow($result))
		{
			$forum_ids[$row['topic_id']] = $row['forum_id'];
		}
		$this->db->sql_freeresult($result);

		foreach($topic_ids as $topic_id)
		{
			if ($this->auth->acl_get('m_', $forum_ids[$topic_id]))
			{
				$a_topic_replied[$topic_id] = true;
			}
		}

		return $a_topic_replied;
	}

	private function check_topic_id($topic_id)
	{
		$sql = 'SELECT forum_id
			FROM ' . TOPICS_TABLE . '
			WHERE topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$forum_id = $this->db->sql_fetchrow($result);
		$forum_id = $forum_id['forum_id'];
		$this->db->sql_freeresult($result);

		if ($this->auth->acl_get('m_', $forum_id))
		{
			$this->b_seecontent = true;
		}
		else if ($this->user->data['user_id'] != ANONYMOUS)
		{
			$sql = "SELECT poster_id, topic_id
				FROM " . POSTS_TABLE . "
				WHERE topic_id = $topic_id
				AND poster_id = " . (int) $this->user->data['user_id'] . "
				AND post_visibility = 1";
			$result = $this->db->sql_query($sql);
			$this->b_topic_replied = $this->db->sql_affectedrows($result) ? true : false;
			$this->db->sql_freeresult($result);
		}

		return (!$this->b_topic_replied && !$this->b_seecontent);
	}

	private function check_post_id($post_id)
	{
		$sql = 'SELECT topic_id
			FROM ' . POSTS_TABLE . '
			WHERE post_id = ' . (int) $post_id ;
		$result = $this->db->sql_query($sql);
		$topic_id = $this->db->sql_fetchrow($result);
		$topic_id = $topic_id['topic_id'];
		$this->db->sql_freeresult($result);

		$this->check_topic_id($topic_id);
	}
}