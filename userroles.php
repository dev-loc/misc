<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Контроллер для работы с ролями пользователей.
 * Запускается кроном
 * (Kohana Framework)
 * 02.07.13
 */

class Controller_Jobs_UserRoles extends Controller_Template_Job 
{
    private $oForumDb = NULL;

    private function forum_db_connect()
    {
        if ( empty( $this->oForumDb ) )
        {
	    if(!defined('ipbwi_BOARD_PATH')){
		define('ipbwi_BOARD_PATH', realpath( dirname(__FILE__) . '../../../../www/forum') . DIRECTORY_SEPARATOR );
	    }

            // Forum DB config
            require_once( ipbwi_BOARD_PATH . 'conf_global.php' );

            $aForumCfg = array(
               'type'       => 'mysql',
               'connection' => array(
                   'hostname' => $INFO['sql_host'],
                   'database' => $INFO['sql_database'],
                   'username' => $INFO['sql_user'],
                   'password' => $INFO['sql_pass'],
               ),
               'table_prefix' => '',
               'charset'      => 'utf8',
               'caching'      => FALSE,
               'profiling'    => TRUE,
            );

            $this->oForumDb = Database::instance( 'forum', $aForumCfg );
        }
    }

    public function worker()
    {
        $nTotal  = 0; // Количество обновленных записей
        $nErrors = 0; // Количество ошибок

        $this->forum_db_connect();

        $aRoles = config::get_roles_map();

        // Получаем группы пользователей форума
        $aGroups  = array();

        $oGrps = DB::select()->from('groups')->execute('forum');

        foreach( $oGrps as $k=>$aGrp )
        {
            $aGroups[ $aGrp['g_title'] ] = $aGrp['g_id'];
        }

        // Проверяем актуальность идентификаторов групп форума
        foreach( $aRoles as $sRole=>$sGrpId )
        {
            if ( !in_array( $sGrpId, $aGroups ) )
            {
                Kohana::$log->add( Log::ERROR, '[UserRoles] ":id" нет в списке идентификаторов групп форума.', 
                    array( 
                       ':id' => $sGrpId,
                    )
                );

                $nErrors++;

                $this->message = __( 'Обновлено :total ролей пользователей [ошибок - :errors]', 
                     array( 
                        ':total'  => $nTotal,
                        ':errors' => $nErrors,
                     )
                );

                return true;
            }
        }


        // Пользователи форума и группы
        $aForumMembers = array();

        // SELECT m.email, g.g_title
        // FROM members m
        //      LEFT JOIN groups g ON m.member_group_id = g.g_id 

    	$oMem = DB::select('m.email', 'g.g_id', 'g.g_title')
    	           ->from( array('members', 'm') )
    	           ->join( array('groups', 'g'), 'LEFT')
    	           ->on('m.member_group_id', '=', 'g.g_id')
                   ->execute('forum');

        foreach( $oMem as $k=>$aMem )
        {
            $aForumMembers[ $aMem['email'] ] = $aMem['g_id'];
        }

        // Пользователи магазина
    	$oQ = DB::select( 'users.*', array('roles.name', 'role_name') )
    	           ->from('users' )
    	           ->join('roles', 'LEFT')
    	           ->on('users.role_id', '=', 'roles.id')
    	          ->where('users.active', '>', '0');

    	$oRows = $oQ->execute();

    	while( $aR = $oRows->current() )
        {
            if ( isset( $aForumMembers[ $aR['email'] ] ) )
            {
                $sForumRole = $aForumMembers[ $aR['email'] ];
                $sStoreRole = $aR['role_name'];

                // Проверка наличия соответствия роли идентификатору группы в связующем массиве
                if ( !isset( $aRoles[ $sStoreRole ] ) )
                {
/*
                    Kohana::$log->add( Log::WARNING, '[UserRoles] Нет соответствия роли ":role" идентификатору группы форума. Email пользователя - :email', 
                        array( 
                           ':role'  => $sStoreRole,
                           ':email' => $aR['email'],
                        )
                    );

                    $nErrors++;
*/
                }
                else
                {
                    // Если роль в магазине не соответствует роли на форуме
                    if ( $sForumRole != $aRoles[ $sStoreRole ] ) // Forum Role != Store Role 
                    {
                        $nNewGroup = $aRoles[ $sStoreRole ];

                        DB::update('members')
                              ->set( array( 'member_group_id' => intval($nNewGroup) ) )
                              ->where( 'email', '=', $aR['email'] )
                              ->limit(1)
                              ->execute('forum');

                        $nTotal++;
                    }
                }
            }
            else
            {
                Kohana::$log->add( Log::WARNING, '[UserRoles] Пользователь с email :email не зарегистрирован на форуме', 
                    array( 
                       ':email' => $aR['email'],
                    )
                );
            }

            $oRows->next();
        }

        $this->message = __( 'Обновлено :total ролей пользователей [ошибок - :errors]', 
             array( 
                ':total'  => $nTotal,
                ':errors' => $nErrors,
             )
        );

        return true;

    } // worker()

}
    
