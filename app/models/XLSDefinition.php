<?php

use tdt\core\datacontrollers\XLSController;

/**
 * Excell definition model
 * @copyright (C) 2011,2013 by OKFN Belgium vzw/asbl
 * @license AGPLv3
 * @author Jan Vansteenlandt <jan@okfn.be>
 */
class XlsDefinition extends SourceType{

    protected $table = 'xlsdefinitions';

    protected $guarded = array('id');

    /**
     * Relationship with the TabularColumns model.
     */
    public function tabularColumns(){
        return $this->morphMany('TabularColumns', 'tabular');
    }

    /**
     * Relationship with the Definition model.
     */
    public function definition(){
        return $this->morphOne('Definition');
    }

     /**
     * Validate the input for this model.
     */
    public static function validate($params){
        return parent::validate($params);
    }

    /**
     * Hook into the save function of Eloquent by saving the parent
     * and establishing a relation to the TabularColumns model.
     *
     * Pre-requisite: parameters have already been validated.
     */
    public function save(array $options = array()){

        $columns = $this->parseColumns($options);

        parent::save();

        foreach($columns as $column){

            $tabular_column = new TabularColumns();
            $tabular_column->index = $column[0];
            $tabular_column->column_name = $column[1];
            $tabular_column->is_pk = $column[3];
            $tabular_column->column_name_alias = $column[2];
            $tabular_column->tabular_type = 'XlsDefinition';
            $tabular_column->tabular_id = $this->id;
            $tabular_column->save();
        }

        return true;
    }

    /**
     * Retrieve the set of create parameters that make up a XLS definition.
     */
    public static function getCreateProperties(){
        return array(
                'uri' => array(
                    'required' => true,
                    'description' => 'The location of the XLS file, either a URL or a local file location.',
                ),
                'sheet' => array(
                    'required' => false,
                    'description' => 'The delimiter of the separated value file.',
                    'default_value' => ',',
                ),
                'has_header_row' => array(
                    'required' => false,
                    'description' => 'Boolean parameter defining if the separated value file contains a header row that contains the column names.',
                    'default_value' => 1,
                ),
                'start_row' => array(
                    'required' => false,
                    'description' => 'Defines the row at which the data (and header row if present) starts in the file.',
                    'default_value' => 1,
                ),
                'documentation' => array(
                    'required' => true,
                    'description' => 'The descriptive or informational string that provides some context for you published dataset.',
                )
        );
    }

     /**
     * Retrieve the set of create parameters that make up a XLS definition.
     * Include the parameters that make up relationships with this model.
     */
    public static function getAllProperties(){
        return array_merge(self::getCreateProperties(), TabularColumns::getCreateProperties());
    }

    /**
     * Retrieve the set of validation rules for every create parameter.
     * If the parameters doesn't have any rules, it's not mentioned in the array.
     */
    public static function getCreateValidators(){
        return array(
            'has_header_row' => 'integer|min:0|max:1',
            'start_row' => 'integer',
            'uri' => 'uri',
        );
    }

    /**
     * Get the file extension from the file name.
     */
    private function getFileExtension($file){
        return strtolower(substr(strrchr($file, '.'), 1));
    }

    /**
     * Retrieve colummn information from the request parameters.
     */
    private function parseColumns($options){

        $aliases = @$options['columns'];
        $pk = @$options['pk'];

        if(empty($aliases)){
            $aliases = array();
        }


        $columns = array();
        $tmp_dir = sys_get_temp_dir();

        if(empty($columns)){
            if (!is_dir($tmp_dir)) {
                mkdir($tmp_dir);
            }

            $is_uri = (substr($this->uri , 0, 4) == "http");

            try{
                if ($is_uri) {
                $tmp_file = uniqid();

                    file_put_contents($tmp_dir. "/" . $tmp_file, file_get_contents($this->uri));
                    $php_obj = XLSController::loadExcel($tmp_dir ."/" . $tmp_file, $this->getFileExtension($this->uri), $this->sheet);
                } else {
                    $php_obj = XLSController::loadExcel($this->uri, $this->getFileExtension($this->uri),$this->sheet);
                }

                $worksheet = $php_obj->getSheetByName($this->sheet);

            }catch(Exception $ex){
                \App::abort(452, "Something went wrong whilst retrieving the Excel file from uri $this->uri.");
            }


            if(is_null($worksheet)){
                \App::abort(452, "The sheet with name, $this->sheet, has not been found in the Excel file.");
            }

            foreach ($worksheet->getRowIterator() as $row) {

                $row_index = $row->getRowIndex();

                if ($row_index == $this->start_row) {

                    $cell_iterator = $row->getCellIterator();
                    $cell_iterator->setIterateOnlyExistingCells(false);

                    $column_index = 0;

                    foreach($cell_iterator as $cell){

                        if($cell->getCalculatedValue() != ""){

                            $cell_value = trim($cell->getCalculatedValue());

                            // Try to get an alias from the options, if it's empty
                            // then just take the column value as alias.
                            $alias = @$aliases[$column_index];

                            if(empty($alias)){
                                $alias = $cell_value;
                            }

                            array_push($columns, array($column_index, $cell->getCalculatedValue(), $alias, $pk == $column_index));
                        }
                        $column_index++;
                    }

                    break;
                }
            }

            $php_obj->disconnectWorksheets();

            if ($is_uri) {
                unlink($tmp_dir . "/" . $tmp_file);
            }
        }

        return $columns;
    }
}