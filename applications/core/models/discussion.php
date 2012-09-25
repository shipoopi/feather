<?php namespace Feather\Core;

use DB;
use Str;
use Cache;
use FeatherModelException;

class Discussion extends Base {

	/**
	 * The table name.
	 * 
	 * @var string
	 */
	public static $table = 'discussions';

	/**
	 * Timestamps are enabled.
	 * 
	 * @var bool
	 */
	public static $timestamps = true;

	/**
	 * A discussion has one author.
	 *
	 * @return object
	 */
	public function author()
	{
		return $this->belongs_to('Feather\\Core\\User', 'user_id');
	}

	/**
	 * A discussion might have a recent poster, otherwise it has nothing.
	 * 
	 * @return object
	 */
	public function recent()
	{
		return $this->belongs_to('Feather\\Core\\User', 'last_reply_user_id');
	}

	/**
	 * A discussion has a place.
	 * 
	 * @return object
	 */
	public function place()
	{
		return $this->belongs_to('Feather\\Core\\Place', 'place_id');
	}

	/**
	 * A discussion can have many participants.
	 * 
	 * @return object
	 */
	public function participants()
	{
		return $this->has_many('Feather\\Core\\Discussion\\Participant', 'discussion_id');
	}

	/**
	 * Getter for a discussions slug.
	 * 
	 * @return string
	 */
	public function get_slug()
	{
		return Str::slug($this->get_attribute('title'));
	}

	/**
	 * Getter for the replies. Turns it into a readable format.
	 * 
	 * @return string
	 */
	public function get_short_replies()
	{
		return $this->shortner($this->get_attribute('replies'));
	}

	/**
	 * Getter for the views. Turns it into a readable format.
	 * 
	 * @return string
	 */
	public function get_short_views()
	{
		return $this->shortner($this->get_attribute('views'));
	}

	/**
	 * Shorten the replies and views numbers
	 * 
	 * @param  int  $number
	 * @return string
	 */
	protected function shortner($number)
	{
		// Oh wow, under a 1,000... Not bad.
		if($number < 1000)
		{
			$number = number_format($number);
		}
		// Hold up, getting up in the thousands!
		elseif($number < 1000000)
		{
			$number = number_format($number / 1000, 1) . 'K';
		}
		// Ah-hoy! A million!
		elseif($number < 1000000000)
		{
			$number = number_format($number / 1000000, 1) . 'M';
		}
		// CRIKEY! A biollion!?
		else
		{
			$number = number_format($number / 1000000000, 1) . 'B';
		}

		return str_replace('.0', '', $number);
	}

	/**
	 * Start a new discussion.
	 * 
	 * @param  array  $input
	 * @return object
	 */
	public static function start($input)
	{
		return static::edit(new static, $input);
	}

	/**
	 * Edit an existing discussion.
	 * 
	 * @param  object  $discussion
	 * @param  array   $input
	 * @return object
	 */
	public static function edit($discussion, $input)
	{
		$increment = false;

		// If the discussion exists then we are only going to increment the total
		// discussions for the place if the discussion is being saved from a draft
		// to public viewing.
		if($discussion->exists)
		{
			$increment = $discussion->draft ? (isset($input['draft']) ? false : true) : false;
		}

		// If the discussion doesn't exist we only increment the total discussions
		// if the discussion is being started, not drafted.
		else
		{
			$increment = isset($input['start']);
		}

		$place = Place::find($input['place']);

		$discussion->fill(array(
			'place_id' => $input['place'],
			'user_id'  => $input['user'],
			'title'	   => isset($input['draft']) ? (empty($input['title']) ? 'Untitled Discussion' : $input['title']) : (isset($input['title']) ? $input['title'] : $discussion->title),
			'body'	   => $input['body'],
			'private'  => empty($input['participants']) ? 0 : 1,
			'draft'	   => isset($input['draft']) ? 1 : 0
		));

		if(!$discussion->save())
		{
			throw new FeatherModelException;
		}

		// If starting a discussion or saving a drafted discussion for public viewing we
		// increment the places total discussions by one.
		if($increment)
		{
			$place->total_discussions += 1;

			$place->save();

			// We can now increment the users discussion count by one if the place allows
			// for a user post count increase.
			if($place->user_post_increment)
			{
				$user = User::find($input['user']);

				$user->total_discussions += 1;

				$user->save();
			}
		}

		$participants = array();

		foreach(explode(',', $input['participants']) as $participant)
		{
			$participants[] = trim($participant);
		}

		$participants = array_filter($participants);

		// Grab all the participants info from the database, make sure they're all valid then
		// use their IDs.
		if($participants and $users = User::where_in('username', $participants)->get())
		{
			$participants = array();

			foreach($users as $key => $user)
			{
				$participants[$user->id] = array(
					'user_id'		=> $user->id,
					'discussion_id' => $discussion->id
				);
			}
		}

		// If this discussion is private we need to add the participants to the participants
		// table. Sync the table, remove any that were removed and add any new ones.
		if($discussion->participants)
		{
			// First thing is to delete any participants where the IDs are not in the
			// submitted participants for this discussion.
			if($participants)
			{
				Discussion\Participant::where_not_in('user_id', array_keys($participants))->where_discussion_id($discussion->id)->delete();
			}
			else
			{
				Discussion\Participant::where_discussion_id($discussion->id)->delete();
			}

			// Spin through the discussions existing participants and remove them from the
			// array if they are already participating.
			foreach($discussion->participants as $participant)
			{
				if(isset($participants[$participant->id]))
				{
					unset($participants[$participant->id]);
				}
			}
		}

		if($participants)
		{
			Discussion\Participant::insert($participants);
		}

		// Clear the cache for this discussion, as well as the place it belongs, all places,
		// and the current user.
		Cache::forget("discussion_{$discussion->id}");

		Cache::forget("place_{$discussion->place_id}");

		Cache::forget('places');

		Cache::forget("user_{$discussion->user_id}");

		return $discussion;
	}

	/**
	 * Returns an enriched array of discussions with relationships loaded.
	 * 
	 * @param  array  $discussions
	 * @return array
	 */
	public static function enrichment($discussions)
	{
		if(!is_array($discussions))
		{
			$discussions = array($discussions);
		}

		$ids = array(
			'participants' => array(),
			'author'	   => array(),
			'recent'	   => array()
		);

		foreach($discussions as $discussion)
		{
			$ids['author'][] = $discussion->user_id;

			$ids['participants'][] = $discussion->id;

			if($discussion->last_reply_user_id)
			{
				$ids['recent'][] = $discussion->last_reply_user_id;
			}
		}

		$ids['author'] = array_unique($ids['author']);

		$ids['recent'] = array_unique($ids['recent']);

		// Here we will run three queries, one to fetch all the authors, another to fetch all the participants
		// and the last will fetch the last poster details, if any.
		$authors_raw = User::where_in('id', $ids['author'])->get();

		$participants_raw = Discussion\Participant::with('details')->where_in('discussion_id', $ids['participants'])->get();

		$recently_raw = $ids['recent'] ? User::where_in('id', $ids['recent'])->get() : array();

		// We now need to sort the arrays better, because at the moment accessing objects within the arrays
		// is very hard. The keys for each array need to be the corrosponding foreign keys for the relationships.
		$authors = array();
		foreach($authors_raw as $author) $authors[$author->id] = $author;

		$participants = array();
		foreach($participants_raw as $participant) $participants[$participant->discussion_id][$participant->id] = $participant;

		$recently = array();

		if($recently_raw)
		{
			foreach($recently_raw as $recent) $recently[$recent->id] = $recent;
		}

		// Now that the arrays are sorted all lovely like we can spin through our discsussions again and
		// assign the relationships to each discussion.
		foreach($discussions as $discussion)
		{
			$discussion->relationships['author'] = $authors[$discussion->user_id];

			if(isset($participants[$discussion->id]))
			{
				$discussion->relationships['participants'] = $participants[$discussion->id];
			}
			else
			{
				$discussion->relationships['participants'] = null;	
			}

			if($discussion->last_reply_user_id)
			{
				$discussion->relationships['recent'] = $recently[$discussion->last_reply_user_id];
			}
			else
			{
				$discussion->relationships['recent'] = null;
			}
		}

		return $discussions;
	}

	/**
	 * Converts participant IDs into a comma separated string.
	 * 
	 * @return string
	 */
	public function participants_to_string()
	{
		$ids = $participants = array();

		foreach($this->participants as $participant)
		{
			$ids[] = $participant->user_id;
		}

		if($ids and $users = User::where_in('id', $ids)->get())
		{
			foreach($users as $user)
			{
				$participants[] = $user->username;
			}
		}

		return implode(', ', $participants);
	}

}