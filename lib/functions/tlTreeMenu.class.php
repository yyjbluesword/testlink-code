<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 * This script is distributed under the GNU General Public License 2 or later.
 *  
 * This file generates tree menu for test specification and test execution.
 * 
 * @filesource	tlTreeMenu.class.php
 * @package 	TestLink
 * @author 		Francisco Mancardi
 * @copyright 	2011, TestLink community 
 * @link 		http://www.teamst.org/index.php
 *
 * @internal revisions
 */
class tlTreeMenu
{

	private $db = null;
	private $tprojectMgr = null;
	private $cfg = null;
	private $tables = null;
	
 	public function __construct(&$dbHandler) 
	{
		$this->db = $dbHandler;
		$this->tprojectMgr = new testproject($dbHandler);

        $this->tables = tlObjectWithDB::getDBTables(array('tcversions','nodes_hierarchy','testplan_tcversions'));

		$this->cfg = new stdClass();
		
		$this->cfg->showTestCaseID = config_get('treemenu_show_testcase_id');
		$this->cfg->glueChar = config_get('testcase_cfg')->glue_character;

		$this->cfg->nodeTypeCode = $this->tprojectMgr->tree_manager->get_available_node_types();
		$this->cfg->nodeCodeType = array_flip($this->cfg->nodeTypeCode);	

		$this->cfg->results = config_get('results');

		$this->cfg->renderTestSpecNode = array();
		$this->cfg->renderTestSpecNode->key2del = array_merge(array_keys($this->cfg->results['status_code']),
															  array('node_type_id','parent_id','node_order',
															  		'node_table','tcversion_id','external_id',
															  		'version','testcase_count'));  

	}

	/*
	 *
	 *
	 */	
	function generateTestSpecTree(&$db,$env,$linkto,$filters=null,$options=null)
	{
		$treeMenu = new stdClass(); 
		$treeMenu->rootnode = null;
		$treeMenu->menustring = '';
		$menustring = null;
	
		$my = array();
		$my['options'] = array('forPrinting' => 0, 'hideTestCases' => 0, 
		                       'tc_action_enabled' => 1, 'ignore_inactive_testcases' => 0, 
		                       'viewType' => 'testSpecTree');
		
	
		$my['filters'] = array('keywords' => null, 'executionType' => null, 'importance' => null,
		                       'testplan' => null, 'filter_tc_id' => null);
	
		$my['options'] = array_merge($my['options'], (array)$options);
		$my['filters'] = array_merge($my['filters'], (array)$filters);
		
		
		$showTestCaseID = config_get('treemenu_show_testcase_id');
		$glueChar = config_get('testcase_cfg')->glue_character;
		
		$exclude_branches = isset($filters['filter_toplevel_testsuite']) && 
							is_array($filters['filter_toplevel_testsuite']) ?
		                    $filters['filter_toplevel_testsuite'] : null;
		
		$tcase_prefix = $this->tprojectMgr->getTestCasePrefix($env['tproject_id']) . $glueChar;
		$test_spec = $this->tprojectMgr->get_subtree($env['tproject_id'],testproject::RECURSIVE_MODE,
			                                    	 testproject::INCLUDE_TESTCASES, $exclude_branches);
		
		// Added root node for test specification -> testproject
		$test_spec['name'] = $env['tproject_name'];
		$test_spec['id'] = $env['tproject_id'];
		$test_spec['node_type_id'] = $this->cfg->nodeTypeCode['testproject'];
		
		$map_node_tccount=array();
		$tplan_tcs=null;
		
		if($test_spec)
		{
			$tck_map = null;  // means no filter
			if(!is_null($my['filters']['filter_keywords']))
			{
				$tck_map = $this->tprojectMgr->get_keywords_tcases($env['tproject_id'],$my['filters']['filter_keywords'],
				           									 	   $my['filters']['filter_keywords_filter_type']);
				if( is_null($tck_map) )
				{
					$tck_map=array();  // means filter everything
				}
			}
			
		    if (isset($my['filters']['filter_custom_fields']) && isset($test_spec['childNodes'])) 
		    {
		    	$test_spec['childNodes'] = $this->filterByCFValues($test_spec['childNodes'],
		    													   $my['filters']['filter_custom_fields']); 
		    }
			
			// Important: 
			// prepareNode() will make changes to $test_spec like filtering by test case keywords using $tck_map;
			$pnFilters = null;
			$keys2init = array('filter_testcase_name','filter_execution_type','filter_priority','filter_tc_id');
			foreach ($keys2init as $keyname) 
			{
				$pnFilters[$keyname] = isset($my['filters'][$keyname]) ? $my['filters'][$keyname] : null;
			}
		    
		    $pnFilters['setting_testplan'] = $my['filters']['setting_testplan'];
		    $pnOptions = array('hideTestCases' => $my['options']['hideTestCases'], 
		    				   'viewType' => $my['options']['viewType'],	
			                   'ignoreInactiveTestCases' => $my['options']['ignore_inactive_testcases']);
			
			$testcase_counters = $this->prepareNode($test_spec,$map_node_tccount,$tck_map,$tplan_tcs,
													$pnFilters,$pnOptions);
	
			foreach($testcase_counters as $key => $value)
			{
				$test_spec[$key]=$testcase_counters[$key];
			}
			
			$rnOptions = array('tc_action_enabled' => $my['options']['tc_action_enabled'],
							   'forPrinting' => $my['options']['forPrinting'],
							   'linkto' => $linkto, 'testCasePrefix' => $tcase_prefix,	
							   'showTestCaseID' => $showTestCaseID);
							   
			$menustring = $this->renderNode($env,1,$test_spec,$rnOptions);
		}
		
		$menustring ='';
		$treeMenu->rootnode = new stdClass();
		$treeMenu->rootnode->name = $test_spec['text'];
		$treeMenu->rootnode->id = $test_spec['id'];
		$treeMenu->rootnode->leaf = isset($test_spec['leaf']) ? $test_spec['leaf'] : false;
		$treeMenu->rootnode->text = $test_spec['text'];
		$treeMenu->rootnode->position = $test_spec['position'];	    
		$treeMenu->rootnode->href = $test_spec['href'];
		
		
		// Change key ('childNodes')  to the one required by Ext JS tree.
		if(isset($test_spec['childNodes']))
		{
			$menustring = str_ireplace('childNodes', 'children', json_encode($test_spec['childNodes'])); 
		}
		// 20090328 - franciscom - BUGID 2299
		// More details about problem found on 20090308 and fixed IN WRONG WAY
		// TPROJECT
		//    |______ TSA
		//            |__ TC1
		//            |__ TC2
		//    | 
		//    |______ TSB
		//            |______ TSC
		// 
		// Define Keyword K1,K2
		//
		// NO TEST CASE HAS KEYWORD ASSIGNED
		// Filter by K1
		// Tree will show root that spins Forever
		// menustring before str_ireplace : [null,null]
		// menustring AFTER [null] 
		//
		// Now fixed.
		//
		// Some minor fix to do
		// Il would be important exclude Top Level Test suites.
		// 
		// 
		// 20090308 - franciscom
		// Changed because found problem on:
		// Test Specification tree when applying Keyword filter using a keyword NOT PRESENT
		// in test cases => Tree root shows loading icon and spin never stops.
		//
		// Attention: do not know if in other situation this will generate a different bug
		// 
		if(!is_null($menustring))
		{
			// Remove null elements (Ext JS tree do not like it ).
			// :null happens on -> "children":null,"text" that must become "children":[],"text"
			// $menustring = str_ireplace(array(':null',',null','null,'),array(':[]','',''), $menustring); 
			$menustring = str_ireplace(array(':null',',null','null,','null'),array(':[]','','',''), $menustring); 
		}
		$treeMenu->menustring = $menustring; 
		
		return $treeMenu;
	}
	


	/**
	 * Filter out the testcases that don't have the given value 
	 * in their custom field(s) from the tree.
	 * Recursive function.
	 * 
	 * @author Andreas Simon
	 * @since 1.9
	 * 
	 * @param array &$tcaseSet reference to test case set/tree to filter
	 * @param array &$cfSet reference to selected custom field information
	 * 
	 * @return array $tcase_tree filtered tree structure
	 * 
	 * @internal revisions
	 * 20110917 - franciscom - refactored
	 * 
	 */
	function filterByCFValues(&$tcaseSet, &$cfSet) 
	{
		static $debugMsg;
		static $cfQty = 0;
		$rows = null;

		if(!$debugMsg)
		{
			$debugMsg = 'Function: ' . __FUNCTION__;
			$cfQty = count($cfSet);
		}
		
		// if we delete a node, numeric indexes of array do have missing numbers ('holes'),
		// which causes problems in later loop constructs in other functions that 
		// assume numeric keys in these arrays without 'holes' -> crashes JS tree!
		// so reindex is needed to fix the array indexes.
		$doReindex = false;

		// $keySet = array_keys()
		foreach($tcaseSet as $key => $node) 
		{
			if ($node['node_type_id'] == $this->cfg->nodeTypeCode['testsuite']) 
			{
				$doDel = true;
				if (isset($node['childNodes']) && is_array($node['childNodes'])) 
				{
					// node is a suite and has children, so recurse one level deeper			
					$tcaseSet[$key]['childNodes'] = $this->filterByCFValues($tcaseSet[$key]['childNodes'], 
					                                                      	$cf_hash);
					
					// now remove testsuite node if it is empty after coming back from recursion
					$doDel = count($tcaseSet[$key]['childNodes']) == 0 ? true : false;
				} 
				
				if ($doDel) 
				{
					unset($tcaseSet[$key]);
					$doReindex = true;
				}			
			} 
			else if ($node['node_type_id'] == $node_type_testcase) 
			{
				// node is testcase, check if we need to delete it
				$doDel = true;
				$sql = " /* $debugMsg */ SELECT CFD.value " . 
					   " FROM {$this->tables['cfield_design_values']} CFD " .
					   " JOIN {$this->tables['nodes_hierarchy']} NH  ON NH.id = CFD.node_id " .
					   " WHERE  NH.parent_id = {$node['id']} ";

				if( isset($cfSet) ) 
				{	
					$cf_sql = '';
					$sqlOR = '';
					foreach ($cfHash as $cf_id => $cf_value) 
					{
						$cf_sql .= $sqlOR;

						if (is_array($cf_value)) 
						{
							$sqlAND = '';
							foreach ($cf_value as $value) 
							{
								$cf_sql .= $sqlAND . "( CFD.value LIKE '%{$value}%' AND CFD.field_id = {$cf_id} )";
								$sqlAND = " AND ";
							}
						} 
						else 
						{
							$cf_sql .= " ( CFD.value LIKE '%{$cf_value}%' ) ";
						}

						$sqlOR = " OR ";
					}
					$sql .=  " AND ({$cf_sql}) ";
				}
	
				$rows = $this->db->fetchColumnsIntoArray($sql,'value');
				
				// if there exist as many rows as custom fields to be filtered by
				// tc does meet the criteria
				// $doDel = (count($rows) != count($cf_hash)) ? true : false;
				if ((count($rows) != cfQty)) 
				{
					unset($tcaseSet[$key]);
					$doReindex = true;
				}
			}
		}
		
		if ($doReindex) 
		{
			$tcaseSet = array_values($tcaseSet);
		}
		
		return $tcaseSet;
	}




	/**
	 * Prepares a Node to be displayed in a navigation tree. (it calls itself).
	 * Used in the construction of:
	 *  - Test project specification -> we want ALL test cases defined in test project.
	 *  - Test execution             -> we only want the test cases linked to a test plan.
	 * 
	 * IMPORTANT:
	 * when analising a container node (Test Suite) if it is empty and we have requested
	 * some sort of filtering NODE WILL BE PRUNED.
	 *
	 *
	 *
	 * tplan_tcases: map with testcase versions linked to test plan. 
	 *               due to the multiples uses of this function, null has to meanings
	 *
	 *         		 When we want to build a Test Project specification tree,
	 *         		 WE SET it to NULL, because we are not interested in a test plan.
	 *         		 
	 *         		 When we want to build a Test execution tree, we dont set it deliverately
	 *         		 to null, but null can be the result of NO tcversion linked.
	 *
	 *
	 * 20081220 - franciscom - status can be an array with multple values, to do OR search.
	 *
	 * 20071014 - franciscom - added version info fro test cases in return data structure.
	 *
	 * 20061105 - franciscom
	 * ignore_inactive_testcases: useful when building a Test Project Specification tree 
	 *                            to be used in the add/link test case to Test Plan.
	 *
	 *
	 * 20061030 - franciscom
	 * tck_map: Test Case Keyword map:
	 *          null            => no filter
	 *          empty map       => filter out ALL test case ALWAYS
	 *          initialized map => filter out test case ONLY if NOT present in map.
	 *
	 *
	 * added argument:
	 *                $map_node_tccount
	 *                key => node_id
	 *                values => node test case count
	 *                          node name (useful only for debug purpouses
	 *
	 *                IMPORTANT: this new argument is not useful for tree rendering
	 *                           but to avoid duplicating logic to get test case count
	 *
	 *
	 * return: map with keys:
	 *         'total_count'
	 *         'passed'  
	 *         'failed'
	 *         'blocked'
	 *         'not run'
	 *
	 * @internal revisions
	 */
	function prepareNode(&$node,&$map_node_tccount,$tck_map = null,
	                     &$tplan_tcases = null,$filters=null, $options=null)
	{
		
		static $status_descr_code;
		static $status_code_descr;
		static $debugMsg;
	    static $my;
	    static $filterOn;
	    static $activeVersionClause;
	    static $filtersApplied;
		static $match;
		static $sqlTCVFilter;
	
	
	    $tpNode = null;
		if (!$debugMsg)
		{
	  	    $debugMsg = 'Class: ' . __CLASS__ . ' - ' . 'Method: ' . __FUNCTION__ . ' - ';
	
			$hash_id_descr = $decoding_info['node_id_descr'];
			$status_descr_code = $decoding_info['status_descr_code'];
			$status_code_descr = $decoding_info['status_code_descr'];
	
			$my = array();
			$my['options'] = array('hideTestCases' => 0, 'showTestCaseID' => 1, 'viewType' => 'testSpecTree',
			                       'getExternalTestCaseID' => 1,'ignoreInactiveTestCases' => 0);
	
			// asimon - added importance here because of "undefined" error in event log
			$my['filters'] = array('status' => null, 'assignedTo' => null, 
			                       'importance' => null, 'executionType' => null,
			                       'filter_tc_id' => null);
			
			$my['options'] = array_merge($my['options'], (array)$options);
			$my['filters'] = array_merge($my['filters'], (array)$filters);
	
			$filterOn['testcase_id'] = isset($my['filters']['filter_tc_id']);
			$filterOn['testcase_name'] = isset($my['filters']['filter_testcase_name']);
			$filterOn['keywords'] = isset($tck_map);
			$filterOn['executionType'] = isset($my['filters']['filter_execution_type']);
			$filterOn['importance'] = isset($my['filters']['filter_priority']);
			$filterOn['custom_fields'] = isset($my['filters']['filter_custom_fields']);

			$sqlTCVFilter = '';
			if( $filterOn['executionType'] )
			{
				$sqlTCVFilter .= " AND TCV.execution_type = {$my['filters']['filter_execution_type']} ";
			}
			if( $filterOn['importance'] )
			{
				$sqlTCVFilter .= " AND TCV.importance = {$my['filters']['filter_priority']} ";
			}

						
			$filtersApplied = false;
			foreach($filterOn as $filterValue)
			{
				$filtersApplied = $filtersApplied || $filterValue; 
			}
			
			$activeVersionClause = ($filterOn['executionType'] || $filterOn['importance']) ? " AND TCV.active=1 " : '';

			$k2l = array('user' => 'filter_assigned_user', 'result' => 'filter_result_result');
			foreach($k2l as $k => $l)
			{
				$match[$k] = isset($my['filters'][$l]) ? $my['filters'][$l] : null;
			}



		}
			
		$counterDomain = array_merge(array('testcase_count'),array_keys($this->cfg->results['status_code']));
		$tcase_counters = array_fill_keys($counterDomain,0);
		$node_type = isset($node['node_type_id']) ? $this->cfg->nodeCodeType[$node['node_type_id']] : null;
	
		if($node_type == 'testcase')
		{
			if( $filterOn['keywords'] && !isset($tck_map[$node['id']]) )
			{
				unset($tplan_tcases[$node['id']]);
				$node = null;
			}
			
			if( ($node && $filterOn['testcase_name']) &&
				(stripos($node['name'], $my['filters']['filter_testcase_name']) === FALSE))
			{
				// IMPORTANT:
				// checking with === here, because function stripos could return 0 when string
				// is found at position 0, if clause would then evaluate wrong because 
				// 0 would be casted to false and we only want to delete node if it really is false
				unset($tplan_tcases[$node['id']]);
				$node = null;
			}
			
			if( ($node && $filterOn['testcase_id']) && ($node['id'] != $my['filters']['filter_tc_id']) ) 
			{
				unset($tplan_tcases[$node['id']]);
				$node = null;
			}
			
			$viewType = $my['options']['viewType'];
			if ($node && $viewType == 'executionTree')
			{
				
				$tpNode = isset($tplan_tcases[$node['id']]) ? $tplan_tcases[$node['id']] : null;
		
				$doDel = is_null($tpNode) || 
						(!is_null($match['result']) && !isset($match['result'][$tpNode['exec_status']]) );
				
				if( !$doDel && !is_null($match['user']) )
				{
					$doDel = ( isset($match['user'][TL_USER_SOMEBODY]) && !is_numeric($tpNode['user_id']) ) || 
							 ( isset($match['user'][TL_USER_NOBODY]) && !is_null($tpNode['user_id']) ) ||
				             ( !isset($match['user'][TL_USER_NOBODY]) && !isset($match['user'][TL_USER_SOMEBODY]) &&
				               !isset($match['user'][$tpNode['user_id']]) );
				}  
			
				if ($doDel) 
				{
					unset($tplan_tcases[$node['id']]);
					$node = null;
				} 
				else 
				{
					$externalID='';
					$node['tcversion_id'] = $tpNode['tcversion_id'];		
					$node['version'] = $tpNode['version'];		
					if ($my['options']['getExternalTestCaseID'])
					{

						// need to understand when this we get this situation
						if (!isset($tpNode['external_id']))
						{
							$sql = " /* $debugMsg - line:" . __LINE__ . " */ " . 
							       " SELECT TCV.tc_external_id AS external_id " .
								   " FROM {$this->tables['tcversions']}  TCV " .
								   " WHERE TCV.id=" . $node['tcversion_id'];
							
							$result = $this->db->exec_query($sql);
							$myrow = $this->db->fetch_array($result);
							$externalID = $myrow['external_id'];
						}
						else
						{
							$externalID = $tpNode['external_id'];
						}	
					}
					$node['external_id'] = $externalID;
				}
			}
			
			if ($node && $my['options']['ignoreInactiveTestCases'])
			{
				// there are active tcversions for this node ???
				// I'm doing this instead of creating a test case manager object, because
				// I think is better for performance.
				//
				$sql=" /* $debugMsg - line:" . __LINE__ . " */ " . 
				     " SELECT count(TCV.id) AS num_active_versions " .
					 " FROM {$this->tables['tcversions']} TCV, {$this->tables['nodes_hierarchy']} NH " .
					 " WHERE NH.parent_id=" . $node['id'] .
					 " AND NH.id = TCV.id AND TCV.active=1";
				
				$result = $this->db->exec_query($sql);
				$myrow = $this->db->fetch_array($result);
				if($myrow['num_active_versions'] == 0)
				{
					$node = null;
				}
			}
			
			// -------------------------------------------------------------------
			if ($node && ($viewType=='testSpecTree' || $viewType=='testSpecTreeForTestPlan') )
			{
				$sql = " /* $debugMsg - line:" . __LINE__ . " */ " . 
				       " SELECT COALESCE(MAX(TCV.id),0) AS targetid, TCV.tc_external_id AS external_id" .
					   " FROM {$this->tables['tcversions']} TCV, {$this->tables['nodes_hierarchy']} NH " .
					   " WHERE  NH.id = TCV.id {$activeVersionClause} AND NH.parent_id={$node['id']} " .
					   " GROUP BY TCV.tc_external_id ";
				   
				$rs = $this->db->get_recordset($sql);
				if( is_null($rs) )
				{
					$node = null;
				}
				else
				{	
				    $node['external_id'] = $rs[0]['external_id'];
				    $target_id = $rs[0]['targetid'];
					
					if( $filterOn['executionType'] || $filterOn['importance'] )
					{
						switch ($viewType)
						{
							case 'testSpecTreeForTestPlan':
								// Try to get info from linked tcversions Platform is not needed
								$sql = " /* $debugMsg - line:" . __LINE__ . " */ " . 
									   " SELECT DISTINCT TPTCV.tcversion_id AS targetid " .
									   " FROM {$this->tables['tcversions']} TCV " .
									   " JOIN {$this->tables['nodes_hierarchy']} NH " .
									   " ON NH.id = TCV.id {$activeVersionClause} " .
									   " AND NH.parent_id={$node['id']} " .
									   " JOIN {$this->tables['testplan_tcversions']} TPTCV " .
									   " ON TPTCV.tcversion_id = TCV.id " .
									   " AND TPTCV.testplan_id = {$my['filters']['setting_testplan']}";
				    			$rs = $this->db->get_recordset($sql);
								$target_id = !is_null($rs) ? $rs[0]['targetid'] : $target_id;
							break;
						}		
						
						$sql = " /* $debugMsg - line:" . __LINE__ . " */ " . 
							   " SELECT TCV.execution_type " .
							   " FROM {$this->tables['tcversions']} TCV " .
							   " WHERE TCV.id = {$target_id} {$sqlTCVFilter}";
		
				    	$rs = $this->db->fetchRowsIntoMap($sql,'execution_type');
				    	if(is_null($rs))
				    	{
				    		$node = null;
				    	}
				    }
				} 
	            if( !is_null($node) )
	            {
					// needed to avoid problems when using json_encode with EXTJS
					unset($node['childNodes']);
					$node['leaf']=true;
				}
			}
			// -------------------------------------------------------------------
			
			
			foreach($tcase_counters as $key => $value)
			{
				$tcase_counters[$key]=0;
			}
			if(isset($tpNode['exec_status']) )
			{
				$tc_status_code = $tpNode['exec_status'];
				$tc_status_descr = $status_code_descr[$tc_status_code];   
			}
			else
			{
				$tc_status_descr = "not_run";
				$tc_status_code = $status_descr_code[$tc_status_descr];
			}
			
			$init_value = $node ? 1 : 0;
			$tcase_counters[$tc_status_descr] = $init_value;
			$tcase_counters['testcase_count'] = $init_value;
			if ( $my['options']['hideTestCases'] )
			{
				$node = null;
			} 
		}  // if($node_type == 'testcase')

		
		if (isset($node['childNodes']) && is_array($node['childNodes']))
		{
			// node has to be a Test Suite ?
			$childNodes = &$node['childNodes'];
			$childNodesQty = count($childNodes);
			
			for($idx = 0;$idx < $childNodesQty ;$idx++)
			{
				$current = &$childNodes[$idx];
				// I use set an element to null to filter out leaf menu items
				if(is_null($current))
				{
					continue;
				}
				
				$counters_map = $this->prepareNode($current,$map_node_tccount,$tck_map,$tplan_tcases,
												   $my['filters'],$my['options']);
				foreach($counters_map as $key => $value)
				{
					$tcase_counters[$key] += $counters_map[$key];   
				}  
			}
			foreach($tcase_counters as $key => $value)
			{
				$node[$key] = $tcase_counters[$key];
			}  
			
			if (isset($node['id']))
			{
				$map_node_tccount[$node['id']] = array(	'testcount' => $node['testcase_count'],
					                                    'name' => $node['name']);
			}
	
	        // node must be destroyed if empty had we have using filtering conditions
			if( ($filtersApplied || !is_null($tplan_tcases)) && 
				!$tcase_counters['testcase_count'] && ($node_type != 'testproject'))
			{
				$node = null;
			}
		}
		else if ($node_type == 'testsuite')
		{
			// does this means is an empty test suite ??? - franciscom 20080328
			$map_node_tccount[$node['id']] = array(	'testcount' => 0,'name' => $node['name']);
			
	        // If is an EMPTY Test suite and we have added filtering conditions,
	        // We will destroy it.
			if ($filtersApplied || !is_null($tplan_tcases) )
			{
				$node = null;
			}	
		}
	
		return $tcase_counters;
	}




	/**
	 * Create the string representation suitable to create a graphic visualization
	 * of a node, for the type of menu selected.
	 *
	 * @internal Revisions
	 * 20100611 - franciscom - removed useless $getArguments
	 */
	function renderNode($env,$level,&$node,$options)
	{
		$menustring='';
		$nodeAttr = array('node_type' => $this->cfg->nodeCodeType[$node['node_type_id']], 
						  'testCasePrefix' => $options['testCasePrefix']);
						  
					 
		$this->renderTestSpecNode($node,$nodeAttr,$options,$env);
		if (isset($node['childNodes']) && $node['childNodes'])
		{
			// need to work always original object in order to change it's values using reference .
			// Can not assign anymore to intermediate variables.
			//
			for($idx = 0, $nChildren = sizeof($node['childNodes']); $idx < $nChildren; $idx++)
			{
				if(!isset($node['childNodes'][$idx]))
				{
					continue;
				}
				$menustring .= $this->renderNode($env,$level+1,$node['childNodes'][$idx],options);
			}
		}
		
		return $menustring;
	}



	/**
	 *
	 * @internal revisions
	 */
	function renderTestSpecNode(&$node,$nodeAttr,$options,$env)
	{
		$name = filterString($node['name']);
		$pfn = "ET";
		$testcase_count = isset($node['testcase_count']) ? $node['testcase_count'] : 0;	
		
		switch($nodeAttr['node_type'])
		{
			case 'testproject':
				$pfn = $options['forPrinting'] ? 'TPROJECT_PTP' : 'EP';
				$label =  $name . " (" . $testcase_count . ")";
				break;
				
			case 'testsuite':
				$pfn = $options['forPrinting'] ? 'TPROJECT_PTS' : 'ETS';
				$label =  $name . " (" . $testcase_count . ")";	
				break;
				
			case 'testcase':
				if (!$options['tc_action_enabled'])
				{
					$pfn = "void";
				}
				
				$label = "";
				if($options['showTestCaseID'])
				{
					$label .= "<b>{$nodeAttr['testCasePrefix']}{$node['external_id']}</b>:";
				} 
				$label .= $name;
				break;
				
		} // switch	
		
		$node['text'] = $label;
		$node['testlink_node_name'] = $name;
	   	$node['testlink_node_type'] = $nodeAttr['node_type'];
		$node['position']=isset($node['node_order']) ? $node['node_order'] : 0;
		
		$node['href']='';
		if(!is_null($pfn))
		{
			$node['href'] = "javascript:{$pfn}({$env['tproject_id']},";
			$node['href'] .= ($pfn == 'ET') ? "{$node['id']})" : "{$env['tplan_id']},{$node['id']})";
		}
		
		// Remove useless keys
		foreach($this->cfg->renderTestSpecNode->key2del as $key)
		{
			if(isset($node[$key]))
			{
				unset($node[$key]); 
			}  
		}
		
	}

} // Class End
