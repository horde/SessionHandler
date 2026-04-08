<?php

class HordeSessionhandlerBaseTables extends Horde_Db_Migration_Base
{
    public function up()
    {
        if (!in_array('horde_sessionhandler', $this->tables())) {
            $t = $this->createTable('horde_sessionhandler', ['autoincrementKey' => false]);
            $t->column('session_id', 'string', ['limit' => 32, 'null' => false]);
            $t->column('session_lastmodified', 'integer', ['null' => false]);
            $t->column('session_data', 'binary');
            $t->primaryKey(['session_id']);
            $t->end();
            $this->addIndex('horde_sessionhandler', ['session_lastmodified']);
        }
    }

    public function down()
    {
        $this->dropTable('horde_sessionhandler');
    }
}
