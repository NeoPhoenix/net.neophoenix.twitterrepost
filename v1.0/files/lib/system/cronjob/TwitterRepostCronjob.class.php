<?php
	
// wbb imports
require_once(WBB_DIR.'lib/data/thread/ThreadEditor.class.php');
require_once(WBB_DIR.'lib/data/board/Board.class.php');
// wcf imports
require_once(WCF_DIR.'lib/data/cronjobs/Cronjob.class.php');
require_once(WCF_DIR.'lib/data/user/User.class.php');
require_once(WCF_DIR.'lib/data/user/UserEditor.class.php');
require_once(WCF_DIR.'lib/data/user/UserProfile.class.php');

class TwitterRepostCronjob implements Cronjob
{
	//Vars		
	public $userClass = null;
	public $plugindata = array
			(
				'userID'		=> TWITTERREPOST_USERID,
				'userName'		=> TWITTERREPOST_USERNAME,
				'boardID'		=> TWITTERREPOST_BOARDID,
				'threadID'		=> TWITTERREPOST_THREADID,
				'twitterName'	=> TWITTERREPOST_TWITTERNAME,
				'twitterCount'	=> TWITTERREPOST_SYNCTWEETS,
				'hashtagFilter'	=> array_filter(array_unique(explode('\n',str_replace('\r','',TWITTERREPOST_HASHTAGFILTER))))
			);
	
	public function __construct()
	{
		if(!TWITTERREPOST_ACTIVATE) exit;
		if(empty($this->plugindata['twitterName'])) exit;
		
		$this->userClass = new User($this->plugindata['userID'],null,null);
		if($this->userClass->userID) $this->plugindata['userName'] = $this->userClass->username;
		else
		{
			$this->plugindata['userID']		= -1;
			$this->plugindata['userName']	= TWITTERREPOST_USERNAME;
		}
	}
	
	// -> Cronjob::execute()
	public function execute($data)
	{
		if(!TWITTERREPOST_ACTIVATE) exit;
		if(empty($this->plugindata['twitterName'])) exit;
		
		$sql = 'SELECT MAX(time) FROM wcf'.WCF_N.'_twitter_repost';
		$result = mysql_query($sql);
		$row = mysql_fetch_row($result);
		$newest_tweet = intval($row[0]);
		
		$timeline = file_get_contents('http://api.twitter.com/1/statuses/user_timeline.xml?screen_name='.$this->plugindata['twitterName'].'&count='.$this->plugindata['twitterCount']);
		$xml = new SimpleXMLElement($timeline);
		foreach(array_reverse($xml->xpath("/statuses/status")) as $tweet)
		{
			$timestamp = intval(strtotime($tweet->created_at));
			if($timestamp <= $newest_tweet) continue;
			$filtered = false;
			if(sizeof($this->plugindata['hashtagFilter']) == 0) $filtered = true;
			else
			{
				foreach($this->plugindata['hashtagFilter'] as $hashtag)
				{
					if(strpos($hashtag,$tweet->text) === false) continue;
					$filtered = true;
					break;
				}
			}
			if(!$filtered) continue;
			
			$hash = md5($timestamp.intval($tweet->user->id).$tweet->text);
			$sql = 'SELECT * FROM wcf'.WCF_N.'_twitter_repost WHERE hash="'.$hash.'"';
			$result = mysql_query($sql);
			if(mysql_num_rows($result)) continue;
			
			$sql = 'INSERT INTO wcf'.WCF_N.'_twitter_repost (hash,time) VALUES ("'.$hash.'","'.$timestamp.'")';
			mysql_query($sql);
			
			$search_for = array('%twittername','%tweetid','%tweet','%userlocation','%userimage','%userurl','%userdescription','%userfollowers','%userbackgroundimage','%date_d','%date_D','%date_j','%date_l','%date_N','%date_S','%date_w','%date_z','%date_W','%date_F','%date_m','%date_M','%date_n','%date_t','%date_L','%date_o','%date_Y','%date_y','%date_a','%date_A','%date_B','%date_g','%date_G','%date_h','%date_H','%date_i','%date_s','%date_u','%date_e','%date_I','%date_O','%date_P','%date_T','%date_Z','%date_c','%date_r','%date_U');
			$replace_with = array($tweet->user->screen_name,$tweet->id,$tweet->text,$tweet->user->location,$tweet->user->profile_image_url,$tweet->user->url,$tweet->user->description,$tweet->user->followers_count,$tweet->user->profile_background_image,date('d',$timestamp),date('D',$timestamp),date('j',$timestamp),date('l',$timestamp),date('N',$timestamp),date('S',$timestamp),date('w',$timestamp),date('z',$timestamp),date('W',$timestamp),date('F',$timestamp),date('m',$timestamp),date('M',$timestamp),date('n',$timestamp),date('t',$timestamp),date('L',$timestamp),date('o',$timestamp),date('Y',$timestamp),date('y',$timestamp),date('a',$timestamp),date('A',$timestamp),date('B',$timestamp),date('g',$timestamp),date('G',$timestamp),date('h',$timestamp),date('H',$timestamp),date('i',$timestamp),date('s',$timestamp),date('u',$timestamp),date('e',$timestamp),date('I',$timestamp),date('O',$timestamp),date('P',$timestamp),date('T',$timestamp),date('Z',$timestamp),date('c',$timestamp),date('r',$timestamp),date('U',$timestamp));
			$title = addslashes(str_replace($search_for,$replace_with,TWITTERREPOST_TITLE_TEMPLATE));
			$title = addslashes(substr($title,0,TWITTERREPOST_TITLE_LEN).(strlen($title)>TWITTERREPOST_TITLE_LEN?'...':''));			
			$pagetext = addslashes(str_replace($search_for,$replace_with,TWITTERREPOST_CONTENT_TEMPLATE));
			if(!TWITTERREPOST_HTML) $pagetext = htmlentities($pagetext);
			if(TWITTERREPOST_POSTING == 0)
			{
				$this->AddThread
					(
						$this->plugindata['boardID'],
						' ',
						$title,
						$pagetext,
						0,
						0,
						0,
						0,
						1,
						1,
						0
					);
			}
			else if(TWITTERREPOST_POSTING == 1)
			{
				$this->AddPost
					(
						$this->plugindata['threadID'],
						$title,
						$pagetext,
						0,
						0,
						1,
						1,
						0
					);
			}
		}
		@unlink($timeline);
		@unlink($xml);
	}
	
	public function AddThread($boardID,$prefix,$headline,$message,$important=0,$closed=0,$disabled=0,$enableSmilies=0,$enableHTML=0,$enableBBCodes=1,$enableSignature=0)
	{
		$board = new BoardEditor($boardID);
		$board->enter();
		$newThread = ThreadEditor::create
			(
				$boardID,
				0,
				$prefix,
				$headline,
				$message,
				$this->plugindata['userID'],
				$this->plugindata['userName'],
				intval($important==1),
				intval($important==2),
				$closed,
				array
				(
					'enableSmilies' 	=> $enableSmilies,
					'enableHtml' 		=> $enableHTML,
					'enableBBCodes' 	=> $enableBBCodes,
					'showSignature' 	=> $enableSignature
				),
				0,
				null,
				null,
				$disabled
			);
		if($this->userClass->userID && $board->countUserPosts)
		{
			require_once(WBB_DIR.'lib/data/user/WBBUser.class.php');
			WBBUser::updateUserPosts($this->userClass->userID,1);
			if(ACTIVITY_POINTS_PER_THREAD)
			{
				require_once(WCF_DIR.'lib/data/user/rank/UserRank.class.php');
				UserRank::updateActivityPoints(ACTIVITY_POINTS_PER_THREAD);
			}
		}
		$board->addThreads();
		$board->setLastPost($newThread);
		//WCF::getCache()->clearResource('stat');
		//WCF::getCache()->clearResource('boardData');
		$newThread->sendNotification
			(
				new Post(
							null,
							array( 
									'postID' 			=> $newThread->firstPostID,
									'message' 			=> $message,
									'enableSmilies' 	=> $enableSmilies,
									'enableHtml' 		=> $enableHTML,
									'enableBBCodes' 	=> $enableBBCodes
								)
						),
						null 
			);
		return $newThread->threadID;
	}
	
	public function AddPost($threadID,$subject,$message,$closed=0,$enableSmilies=0,$enableHTML=1,$enableBBCodes=1,$enableSignature=1)
	{		
		$Thread		= new ThreadEditor($threadID,null,null);
		$ThreadID	= $Thread->threadID;		
		$Board		= new BoardEditor($Thread->boardID);		
		$disablePost=0;
		if($Thread->isDisabled) $disablePost=1;
		$newPost=PostEditor::create($Thread->threadID,$subject,$message,$this->plugindata['userID'],$this->plugindata['userName'],array
			(
				'enableSmilies'=>$enableSmilies,
				'enableHtml'=>$enableHTML,
				'enableBBCodes'=>$enableBBCodes,
				'showSignature'=>$enableSignature
			),
			null,null,null,intval($disablePost));
		$Thread->addPost($newPost,$closed);						
		if($this->userClass->userID && $Board->countUserPosts)
		{
			require_once(WBB_DIR.'lib/data/user/WBBUser.class.php');
			WBBUser::updateUserPosts($this->userClass->userID,1);
			if(ACTIVITY_POINTS_PER_THREAD)
			{
				require_once(WCF_DIR.'lib/data/user/rank/UserRank.class.php');
				UserRank::updateActivityPoints(ACTIVITY_POINTS_PER_THREAD);
			}
		}			
		$Board->addPosts();
		$Board->setLastPost($Thread);
		//WCF::getCache()->clearResource('stat');
		//WCF::getCache()->clearResource('boardData');
		$newPost->sendNotification($Thread,$Board,null);
	}
}

?>