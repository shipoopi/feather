<?php namespace Feather\Core;

use DB;
use Cache;
use Feather\Auth;
use Feather\Paginator;

class Place extends Ness {

	/**
	 * The table name.
	 * 
	 * @var string
	 */
	public static $table = 'places';

	/**
	 * Timestamps are disabled.
	 * 
	 * @var bool
	 */
	public static $timestamps = false;

	/**
	 * A place has many permissions.
	 * 
	 * @return object
	 */
	public function permissions()
	{
		return $this->has_many('Feather\\Core\\Permission', 'place_id');
	}

	/**
	 * A place has many moderators.
	 * 
	 * @return object
	 */
	public function moderators()
	{
		return $this->has_many('Feather\\Core\\Place\\Moderator', 'place_id');
	}

	/**
	 * A place has many discussions.
	 * 
	 * @return object
	 */
	public function discussions()
	{
		return $this->has_many('Feather\\Core\\Discussion', 'place_id');
	}

	/**
	 * Returns all enriched places.
	 * 
	 * @return array
	 */
	public static function all()
	{
		return Cache::remember('places', function()
		{
			$table = DB::connection(FEATHER_DATABASE)->config['prefix'] . Place::$table;

			$sql = "SELECT node.*, (COUNT( parent.name ) -1) AS depth
				FROM {$table} AS node
				CROSS JOIN places AS parent
				WHERE node.lft BETWEEN parent.lft
				AND parent.rgt
				GROUP BY node.id
				ORDER BY node.lft";

			return Place::enrichment(DB::connection(FEATHER_DATABASE)->query($sql));
		}, static::cache_time);
	}

	/**
	 * Select only one place and limit the number of discussions per page.
	 * 
	 * @param  int  $id
	 * @param  int  $discussions_per_page
	 * @return Feather\Core\Place
	 */
	public static function one($id, $discussions_per_page = 20)
	{
		$place = Cache::remember("place_{$id}", function() use ($id)
		{
			$table = DB::connection(FEATHER_DATABASE)->config['prefix'] . Place::$table;

			$sql = "SELECT node.*, (COUNT( parent.name ) -1) AS depth
				FROM {$table} AS node
				CROSS JOIN places AS parent
				WHERE node.id = {$id}
				AND node.lft BETWEEN parent.lft
				AND parent.rgt
				GROUP BY node.id
				ORDER BY node.lft";

			$place = Place::enrichment(DB::connection(FEATHER_DATABASE)->query($sql));

			return reset($place);
		}, static::cache_time);

		$places = static::filter_by_permissions(array($place->id => $place) + static::enrichment($place->children()));

		list($sorted, $pivot) = static::sort($places);

		// Tally the total number of discussions within each place.
		$totals = static::tally_discussions($places);

		$page = Paginator::page($total_results = array_sum($totals), $discussions_per_page);

		$skip = ($page - 1) * $discussions_per_page;

		// With our paginator variables we can now select only the discussions we want from the database.
		$discussions = !$places ? array() : Discussion::where_in('place_id', array_keys($places))
			->order_by('updated_at', 'desc')
			->skip($skip)
			->take($discussions_per_page)
			->get();

		if($discussions)
		{
			$discussions = Discussion::enrichment($discussions, array('author', 'recent', 'participants'));
		}

		$places = static::refine($discussions, $places, $sorted, $pivot, $totals, $discussions_per_page);

		if(empty($places))
		{
			return array();
		}

		$place = reset($places);

		$pagination = Paginator::make($place->discussions, $total_results, $discussions_per_page);

		return $place->fill(array(
			'discussions' => $pagination->results,
			'pagination'  => $pagination->links()
		));
	}

	/**
	 * Returns an array of places for the index page of Feather. Places will receive a set amount of
	 * newest discussions from any of the places within the root place.
	 * 
	 * @param  int  $discussions_per_place
	 * @return array
	 */
	public static function index($discussions_per_place)
	{
		$places = static::filter_by_permissions(static::all());

		list($sorted, $pivot) = static::sort($places);

		// Spin through the sorted places and build an array of all the IDs including the child places.
		// We'll then fetch the discussions for each place. This isn't exactly ideal but generally there
		// shouldn't be a whole lot of root level places.
		$discussions = array();

		foreach($sorted as $place)
		{
			$ids = (array) $place->id;

			foreach($place->children as $child) $ids[] = $child->id;

			foreach(Discussion::enrichment(Discussion::where_in('place_id', $ids)->order_by('updated_at', 'desc')->take($discussions_per_place * 3)->get()) as $discussion)
			{
				$discussions[] = $discussion;
			}
		}

		// Tally the total number of discussions within each place.
		$totals = static::tally_discussions($places);

		// Returns a refined array consisting of all places with the correct discussions
		// and totals assigned to it.
		return static::refine($discussions, $places, $sorted, $pivot, $totals, $discussions_per_place);
	}

	/**
	 * Filters discussions into the correct parent place and tallies up the remaining discussions for
	 * each.
	 * 
	 * @param  array   $discussions
	 * @param  array   $places
	 * @param  array   $sorted
	 * @param  array   $intermediate
	 * @param  string  $limit
	 * @param  int     $totals
	 * @return array
	 */
	protected static function refine($discussions, $places, $sorted, $pivot, $totals, $limit)
	{
		// Discussions need to be ordered by their updated_at datetime field and then placed into their
		// respective ancestors array.
		$ordered = array();

		foreach($discussions as $discussion)
		{
			if(Auth::cannot('view: discussion', $discussion))
			{
				continue;
			}

			$discussion->place = $places[$discussion->place_id];

			// If the discussions place is a child then we need to use it's parent ID.
			$id = isset($discussion->place->parent_id) ? $discussion->place->parent_id : $discussion->place->id;

			$ordered[$id]["{$discussion->updated_at} {$discussion->id}"] = $discussion;

			// Re-order the ordered discussions by key and in reverse. Newest first.
			krsort($ordered[$id]);
		}

		// Loop over the ordered results and add the latest discussions to each until
		// the limit is reached.
		$filtered = array();

		foreach($ordered as $id => $discussions)
		{
			foreach($discussions as $discussion)
			{
				if(!array_key_exists($id, $filtered) or count($filtered[$id]) < $limit)
				{
					$filtered[$id][] = $discussion;
				}
				else
				{
					break;
				}
			}
		}

		// Loop over the places array and tally up the total discussions for each place.
		foreach($places as $place)
		{
			// If the discussions place is a child then we need to use it's parent ID.
			$id = isset($place->parent_id) ? $place->parent_id : $place->id;

			// Update the discussion counter for the root level place.
			$sorted[$pivot[$id]]->total->discussions += $totals[$place->id];

			// To achieve a cascading discussion count, the child and all its ancestors also
			// get the places discussions added to their total.
			foreach($sorted[$pivot[$id]]->children as $key => $child)
			{
				if($place->id == $child->id or ($place->lft > $child->lft and $place->rgt < $child->rgt))
				{
					$sorted[$pivot[$id]]->relationships['children'][$key]->total->discussions += $totals[$place->id];
				}
			}
		}

		foreach($sorted as $place)
		{
			$place->discussions = array();

			if(array_key_exists($place->id, $filtered))
			{
				$place->discussions = $filtered[$place->id];

				$place->total->remaining = $place->total->discussions - count($filtered[$place->id]);

				if($place->total->remaining < 0)
				{
					$place->total->remaining = 0;
				}
			}
		}

		return $sorted;
	}

	/**
	 * Count the total number of discussions for each place.
	 * 
	 * @param  array  $places
	 * @return array
	 */
	protected static function tally_discussions($places)
	{
		// Creates an array where the keys are the place IDs and the value for each is 0.
		$totals = array_fill_keys(array_keys(array_flip(array_keys($places))), 0);

		if(empty($places)) return $totals;

		$aggregates = Discussion::select(array('place_id', DB::raw('COUNT(*) as aggregate')))
						->where_in('place_id', array_keys($places))
						->where_draft(0)
						->group_by('place_id')
						->get();

		foreach($aggregates as $aggregate)
		{
			$totals[$aggregate->place_id] = $aggregate->aggregate;
		}

		return $totals;
	}

	/**
	 * Takes an array of places and transforms it into a multi-dimensional array of places
	 * sorted into their greatest grand parent.
	 * 
	 * @param  array  $places
	 * @return array
	 */
	protected static function sort($places)
	{
		$sorted = $pivot = array();

		// The gauge is set when the first parent is encountered. Children will then be sorted if their
		// depth is greater than that of the gauge.
		$gauge = null;

		foreach($places as $place)
		{
			if(is_null($gauge) or $place->depth == $gauge)
			{
				$sorted[] = $place;

				$pivot[$place->id] = count($sorted) - 1;

				$gauge = $place->depth;
			}
			else
			{
				$place->parent_id = $sorted[count($sorted) - 1]->id;

				$sorted[count($sorted) - 1]->relationships['children'][] = $place;
			}
		}

		return array($sorted, $pivot);
	}

	/**
	 * Filters out places a user cannot view due to permissions.
	 * 
	 * @param  array  $places
	 * @return array
	 */
	protected static function filter_by_permissions($places)
	{
		// Store the previous ID here so that we can check if the last place was a parent,
		// and if we remove a child we'll check to see if it is no longer a parent.
		$previous = null;

		// If a childs parent is not visible to a user then the child is also deemed
		// not visible.
		$until = null;

		// Make sure the user has the correct permissions to view the places.
		foreach((array) $places as $place)
		{
			if(Auth::cannot('view: place', $place) or $place->rgt < $until)
			{
				// If the previous item is a parent then we'll subtract its right value until
				// it no longer becomes a parent.
				if($previous and $places[$previous]->parent)
				{
					$places[$previous]->rgt -= ($place->rgt - $place->lft) + 1;

					if($places[$previous]->rgt - $places[$previous]->lft <= 1)
					{
						$places[$previous]->parent = false;
					}
				}

				// If this place is a parent then we remove all of its children as well.
				if($place->parent)
				{
					$until = $place->rgt;
				}

				unset($places[$place->id]);

				continue;
			}

			$previous = $place->id;
		}


		return $places;
	}

	/**
	 * Enriches an array of places with some extremely useful properties that are used throughout
	 * the system.
	 * 
	 * @param  array   $places
	 * @param  string  $selected
	 * @return array
	 */
	protected static function enrichment($places, $selected = null)
	{
		$enriched = array();

		foreach($places as $place)
		{
			if(!$place instanceof Place)
			{
				$place = new static((array) $place, true);
			}

			$place->attributes = array_merge($place->attributes, array(
				'parent'   => ($place->rgt - $place->lft) > 1,
				'child'	   => ($place->depth > 0),
				'selected' => ($place->slug == $selected),
				'total'	   => (object) array('discussions' => 0, 'remaining' => 0)
			));

			$place->relationships = array_merge($place->relationships, array(
				'children'	  => array(),
				'moderators'  => array(),
				'permissions' => array()
			));

			// Setting the original to be the same as the attributes prevents all the special properties from
			// being seen as dirty and thus saved when we save a place.
			$place->original = $place->attributes;

			$enriched[$place->id] = $place;
		}

		if($enriched)
		{
			// Fetch all of the permissions for all of the places. Once we have the permissions we'll
			// loop over all the permissions and assign them to their correct place.
			$permissions = Permission::where_in('place_id', array_keys($enriched))->get();

			foreach($permissions as $permission)
			{
				$enriched[$permission->place_id]->relationships['permissions'][$permission->id] = $permission;
			}

			unset($permissions);

			// We'll do the same with the moderators as we did with the permissions. Find them all then assign
			// them to the correct place.
			$moderators = Place\Moderator::with('details')->where_in('place_id', array_keys($enriched))->get();

			foreach($moderators as $moderator)
			{
				$enriched[$moderator->place_id]->relationships['moderators'][$moderator->id] = $moderator;
			}

			unset($moderators);
		}

		return $enriched;
	}

	/**
	 * Returns an HTML select box options list.
	 * 
	 * @param  array  $parameters
	 * @return array
	 */
	public static function options($parameters = array())
	{
		// Build a default parameters array. I decided to use an array here because a whole bunch of
		// parameters is just unwieldly. This is somewhat nicer.
		$parameters = (object) array_merge(array(
			'places'   	  => null,
			'disable'  	  => array(),
			'postable' 	  => false,
			'cascade'  	  => true,
			'selected'	  => null,
			'padder'	  => '---',
			'permissions' => false,
			'action'	  => null
		), $parameters);

		if(is_null($parameters->places))
		{
			$parameters->places = static::all();
		}

		// The disabling variable will store a currently being disabled place. If we are cascading the
		// disabling, that is, disabling a places children as well, then we'll use this variable to check
		// if the places are children.
		$disabling = null;

		// The hiding variable is the same as the disabling variable except it's permission based. If a
		// user cannot view a place then all children of that place will be hidden as well.
		$hiding = null;

		$attributes = array(
			'disabled' => ' disabled="disabled"',
			'selected' => ' selected="selected"'
		);

		$callback = function($place) use ($parameters)
		{
			return str_pad(" {$place->name}", ($place->depth * strlen($parameters->padder)) + strlen($place->name), $parameters->padder, STR_PAD_LEFT);
		};

		$options = array();

		foreach($parameters->places as $key => $place)
		{
			$disabled = $parameters->postable and !$place->postable ? $attributes['disabled'] : '';
			$selected = $place->id == $parameters->selected ? $attributes['selected'] : '';

			// If the place is to be disabled then set the disabled attribute. Also sets the cascading
			// for children if it's enabled. We also check if an action has been passed in and if so run
			// that through our access control. This is handy for disabling places when starting a discussion.
			if(in_array($place->id, (array) $parameters->disable) or ($parameters->action and Auth::cannot($parameters->action, $place)))
			{
				if($parameters->cascade)
				{
					$disabling = $place;
				}

				$disabled = $attributes['disabled'];
			}

			// Otherwise if the diasbling variable is an object check to see if the current place is a
			// child of the originally disabled place, if it is then disable it as well.
			elseif(is_object($disabling) and $place->lft > $disabling->lft and $place->rgt < $disabling->rgt)
			{
				$disabled = $attributes['disabled'];
			}

			// If the hiding variable is an object check to see if the current place is a child of the
			// originally hidden place, if it is hide the child as well.
			elseif(is_object($hiding) and $place->lft > $hiding->lft and $place->rgt < $hiding->rgt)
			{
				continue;
			}

			// If the user cannot view this place then we'll hide it, as well as that, we'll hide
			// any children of this place.
			if($parameters->permissions and Auth::cannot('view: place', $place))
			{
				$hiding = $place;

				continue;
			}

			$options[] = '<option value="' . $place->id . '"' . $disabled . $selected . '>' . $callback($place) . '</option>';
		}

		return implode(PHP_EOL, $options);
	}

}