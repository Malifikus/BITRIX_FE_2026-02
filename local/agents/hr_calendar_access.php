<?php

function hrAddCalendarAccessAgent()
{
    global $DB;
    
    $sql = "SELECT cs.ID as SECT_ID
            FROM b_calendar_section cs
            WHERE cs.CAL_TYPE = 'user'
            AND NOT EXISTS (
                SELECT 1 FROM b_calendar_access ca
                WHERE ca.ACCESS_CODE = 'DR24' 
                AND ca.TASK_ID = 35 
                AND ca.SECT_ID = cs.ID
            )";
    
    $result = $DB->Query($sql);
    
    while ($row = $result->Fetch()) {
        $DB->Query("
            INSERT INTO b_calendar_access (ACCESS_CODE, TASK_ID, SECT_ID) 
            VALUES ('DR24', 35, " . $row['SECT_ID'] . ")
        ");
    }
    
    return "hrAddCalendarAccessAgent();";
}