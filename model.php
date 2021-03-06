<?php
namespace TORM;

class Model {
   public  static $connection  = null;
   private static $table_name  = array();
   private static $order       = array();
   private static $pk          = array();
   private static $columns     = array();
   private static $ignorecase  = array();
   private static $mapping     = array();
   private static $loaded      = array();

   private static $prepared_cache = array();
   private static $validations    = array();
   private static $has_many       = array();
   private static $belongs_to     = array();
   private static $sequence       = array();

   private $data        = array();
   private $new_rec     = false;
   public  $errors      = null;

   /**
    * Constructor
    * If data is sent, then it loads columns with it.
    * @param array $data
    * @package TORM
    */
   public function __construct($data=null) {
      $cls = get_called_class();
      self::checkLoaded();

      if($data==null) {
         $this->new_rec = true;
         $this->data = self::loadNullValues();
         return;
      }

      foreach($data as $key=>$value) {
         if(preg_match("/^\d+$/",$key))
            continue;
         $keyr = $key;
         if(self::isIgnoringCase()) {
            $keyr = strtolower($key);
            $data[$keyr] = $value;
            if($keyr!=$key)
               unset($data[$key]);
         }
         if(!array_key_exists($cls,self::$mapping))
            self::$mapping[$cls] = array();

         if(!array_key_exists($keyr,self::$mapping[$cls]))
            self::$mapping[$cls][$key] = $keyr;
      }
      $this->data = $data;
   }

   public static function isIgnoringCase() {
      $cls = get_called_class();
      if(!array_key_exists($cls,self::$ignorecase))
         return true;
      return self::$ignorecase[$cls];
   }

   /**
    * Load null values on row columns.
    * Useful to new objects.
    */
   private static function loadNullValues() {
      $values = array();
      $cls    = get_called_class();

      if(!array_key_exists($cls,self::$columns))
         return null;

      foreach(self::$columns[$cls] as $column) {
         $name = self::isIgnoringCase() ? strtolower($column) : $column;
         $values[$column] = null;
      }
      return $values;
   }

   public static function setTableName($table_name) {
      $cls = get_called_class();
      self::$table_name[$cls] = $table_name;
   }

   /**
    * Returns the table name.
    * If not specified one, get the current class name and appends a "s" to it.
    * @return string table name
    */
   public static function getTableName() {
      $cls  = get_called_class();
      if(array_key_exists($cls,self::$table_name))
         return self::$table_name[$cls];
      $name = get_called_class()."s";
      if(self::isIgnoringCase())
         $name = strtolower($name);
      return $name;
   }

   public static function setPK($pk) {
      $cls = get_called_class();
      self::$pk[$cls] = $pk;
   }

   /**
    * Returns the primary key column.
    * @return string primary key
    */
   public static function getPK() {
      $cls = get_called_class();
      return array_key_exists($cls,self::$pk) ? self::$pk[$cls] : "id";
   }

   public static function setOrder($order) {
      $cls = get_called_class();
      self::$order[$cls] = $order;
   }

   /**
    * Returns the default order.
    * If not specified, returns an empty string.
    * @return string order
    */
   public static function getOrder() {
      $cls = get_called_class();
      return array_key_exists($cls,self::$order) ? self::$order[$cls] : "";
   }

   /**
    * Returns the inverse order.
    * If DESC is specified, retuns ASC.
    * @return string order
    */
   public static function getReversedOrder() {
      $sort = preg_match("/desc/i",self::getOrder());
      $sort = $sort ? " ASC " : " DESC ";
      return self::getOrder() ? self::getOrder()." $sort" : "";
   }

   /**
    * Resolve the current connection handle.
    * Get it from PDO.
    * @return object connection
    */
   private static function resolveConnection() {
      return self::$connection ? self::$connection : Connection::getConnection();
   }

   /**
    * Load column info
    */
   private static function loadColumns() {
      if(!self::resolveConnection())
         return;

      $cls = get_called_class();
      self::$columns[$cls] = array();

      $escape = Driver::$escape_char;

      // try to create the TORM info table
      $type = Driver::$numeric_column;
      $rst  = self::resolveConnection()->query("create table torm_info (id $type(1))");
      $rst  = self::resolveConnection()->query("select id from torm_info");
      if(!$rst->fetch())
         self::resolveConnection()->query("insert into torm_info values (1)");

      // hack to dont need a query string to get columns
      $sql  = "select $escape".self::getTableName()."$escape.* from torm_info left outer join $escape".self::getTableName()."$escape on 1=1";
      $rst  = self::resolveConnection()->query($sql);
      $keys = array_keys($rst->fetch(\PDO::FETCH_ASSOC));

      foreach($keys as $key) {
         $keyc = self::isIgnoringCase() ? strtolower($key) : $key;
         array_push(self::$columns[$cls],$keyc);
         self::$mapping[$cls][$keyc] = $key;
      }
      self::$loaded[$cls] = true;
   }

   public static function extractUpdateColumns($values) {
      $cls = get_called_class();
      $temp_columns = "";
      $escape = Driver::$escape_char;
      foreach($values as $key=>$value)
         $temp_columns .= "$escape".self::$mapping[$cls][$key]."$escape=?,";
      return substr($temp_columns,0,strlen($temp_columns)-1);
   }

   private static function extractWhereConditions($conditions) {
      if(!$conditions)
         return "";

      $cls = get_called_class();
      $escape = Driver::$escape_char;
      if(is_array($conditions)) {
         $temp_cond = "";
         foreach($conditions as $key=>$value)
            $temp_cond .= "$escape".self::getTableName()."$escape.$escape".self::$mapping[$cls][$key]."$escape=? and ";
         $temp_cond  = substr($temp_cond,0,strlen($temp_cond)-5);
         $conditions = $temp_cond;
      }
      return $conditions;
   }

   public static function extractWhereValues($conditions) {
      $values = array();
      if(!$conditions)
         return $values;

      if(is_array($conditions)) {
         foreach($conditions as $key=>$value)
            array_push($values,$value);
      }
      return $values;
   }

   /**
    * Use the WHERE clause to return values
    * @conditions string or array - better use is using an array
    * @return Collection of results
    */
   public static function where($conditions) {
      self::checkLoaded();

      $builder          = self::makeBuilder();
      $builder->where   = self::extractWhereConditions($conditions);
      $vals             = self::extractWhereValues($conditions);
      return new Collection($builder,$vals,get_called_class());
   }

   private static function makeBuilder() {
      $builder = new Builder();
      $builder->table = self::getTableName();
      $builder->order = self::getOrder();
      return $builder;
   }

   /**
    * Find an object by its primary key
    * @param object $id - primary key
    * @return object result
    */
   public static function find($id) {
      self::checkLoaded();

      $pk               = self::isIgnoringCase() ? strtolower(self::getPK()) : self::getPK();
      $builder          = self::makeBuilder();
      $builder->where   = self::extractWhereConditions(array($pk=>$id));
      $builder->limit   = 1;
      $cls  = get_called_class();
      $stmt = self::executePrepared($builder,array($id));
      $data = $stmt->fetch(\PDO::FETCH_ASSOC);
      if(!$data)
         return null;
      return new $cls($data);
   }

   /**
    * Return all values
    * @return Collection values
    */
   public static function all($conditions=null) {
      self::checkLoaded();

      $builder = self::makeBuilder();
      $vals    = null;
      if($conditions) {
         $builder->where = self::extractWhereConditions($conditions);
         $vals           = self::extractWhereValues($conditions);
      }
      return new Collection($builder,$vals,get_called_class());
   }

   /**
    * Get result by position - first or last
    * @param $position first or last
    * @param object conditions
    * @return result or null
    */
   private static function getByPosition($position,$conditions=null) {
      self::checkLoaded();

      $builder          = self::makeBuilder();
      $builder->order   = $position=="first" ? self::getOrder() : self::getReversedOrder();
      $builder->where   = self::extractWhereConditions($conditions);
      $vals             = self::extractWhereValues($conditions);
      
      $cls  = get_called_class();
      $stmt = self::executePrepared($builder,$vals);
      $data = $stmt->fetch(\PDO::FETCH_ASSOC);
      if(!$data)
         return null;
      return new $cls($data);
   }

   /**
    * Return the first value.
    * Get by order.
    * @param conditions
    * @return object result
    */
   public static function first($conditions=null) {
      return self::getByPosition("first",$conditions);
   }

   /**
    * Return the last value.
    * Get by inverse order.
    * @return object result
    */
   public static function last($conditions=null) {
      return self::getByPosition("last",$conditions);
   }

   /**
    * Tell if its a new object (not saved)
    * @return boolean new or not 
    */
   public function is_new() {
      return $this->new_rec;
   }

   /**
    * Return the object current values
    * @return Array data
    */
   public function getData() {
      return $this->data;
   }

   private function checkLoaded() {
      $cls = get_called_class();
      if(!array_key_exists($cls,self::$loaded))
         self::$loaded[$cls] = false;
      if(!self::$loaded[$cls])
         self::loadColumns();
   } 

   /**
    * Save or update currenct object
    * @return boolean saved/updated
    */
   public function save() {
      if(!$this->isValid())
         return false;

      if(!self::$loaded) 
         self::loadColumns();

      $calling    = get_called_class();
      $pk         = $calling::isIgnoringCase() ? strtolower($calling::getPK()) : $calling::getPK();
      $pk_value   = $this->data[$pk];
      $sql        = null;
      $attrs      = $this->data;
      $rtn        = false;
      $vals       = array();
      $escape     = Driver::$escape_char;

      if($pk_value) {
         $existing      = self::find($pk_value);
         $this->new_rec = !$existing;
      }

      if($this->new_rec) {
         $sql = "insert into $escape".$calling::getTableName()."$escape (";

         // remove the current value when need to insert a NULL value to create 
         // the autoincrement value
         if(Driver::$primary_key_behaviour==Driver::PRIMARY_KEY_DELETE && !$pk_value)
            unset($attrs[$calling::getPK()]);

         if(Driver::$primary_key_behaviour==Driver::PRIMARY_KEY_SEQUENCE && empty($pk_value)) {
            // build the sequence name column. the primary key attribute will
            // result with the key as the primary key column name and value as 
            // the sequence name column value, for example, 
            // {"id"=>"user_sequence.nextval"} 
            $seq_name = self::resolveSequenceName();
            $attrs[$calling::getPK()] = $seq_name.".nextval";

            // check if the sequence exists
            self::checkSequence();
            if(!self::sequenceExists()) {
               $this->addError($pk,"Sequence $seq_name could not be created");
               return false;
            }
         } 

         // use sequence, but there is already a value on the primary key
         // remember that it will allow this only if is really a record that
         // wasn't found when checking for the primary key, specifying that its 
         // a new record!
         if(Driver::$primary_key_behaviour==Driver::PRIMARY_KEY_SEQUENCE && !empty($pk_value))
            $attrs[$calling::getPK()] = $pk_value;

         // marks to insert values on prepared statement
         $marks = array();
         foreach($attrs as $attr=>$value) {
            $sql .= "$escape".self::$mapping[$calling][$attr]."$escape,";
            // can't use marks for sequence values - must be specified the 
            // sequence name column, get as the value specified on the array 
            // created above ({"id"=>"user_sequence.nextval"}).
            array_push($marks, Driver::$primary_key_behaviour==Driver::PRIMARY_KEY_SEQUENCE && $attr==$calling::getPK() && empty($pk_value) ? $value : "?");
         }
         $marks = join(",",$marks); 
         $sql   = substr($sql,0,strlen($sql)-1);
         $sql  .= ") values ($marks)";

         // now fill the $vals array with all values to be inserted on the 
         // prepared statement
         foreach($attrs as $attr=>$value) {
            // can't pass a dynamic value here because there is no mark to be 
            // filled, see above. for sequences, $vals will be an array with one 
            // less dynamic value mark.
            if(Driver::$primary_key_behaviour==Driver::PRIMARY_KEY_SEQUENCE &&
               $attr==$calling::getPK() && empty($pk_value))
               continue;
            array_push($vals,$value);
         }
         $rtn = self::executePrepared($sql,$vals)->rowCount()==1;
      } else {
         unset($attrs[$pk]);
         $sql  = "update $escape".$calling::getTableName()."$escape set ";
         foreach($attrs as $attr=>$value) {
            if(strlen(trim($value))<1)
               $value = "null";
            $sql .= "$escape".self::$mapping[$calling][$attr]."$escape=?,";
            array_push($vals,$value);
         }
         $sql  = substr($sql,0,strlen($sql)-1);
         $sql .= " where $escape".self::getTableName()."$escape.$escape$pk$escape=?";
         array_push($vals,$pk_value);
         $rtn = self::executePrepared($sql,$vals)->rowCount()==1;
      }
      Log::log($sql);
      return $rtn;
   }

   /**
    * Destroy the current object
    * @return boolean destroyed or not
    */
   public function destroy() {
      if(!self::$loaded) 
         self::loadColumns();

      $calling    = get_called_class();
      $table_name = $calling::getTableName();
      $pk         = $calling::isIgnoringCase() ? strtolower($calling::getPK()) : $calling::getPK();
      $pk_value   = $this->data[$pk];
      $escape     = Driver::$escape_char;
      $sql        = "delete from $escape$table_name$escape where $escape$table_name$escape.$escape".self::$mapping[$calling][$pk]."$escape=?";
      Log::log($sql);
      return self::executePrepared($sql,array($pk_value))->rowCount()==1;
   }

   /**
    * Execute a prepared statement.
    * Try to get it from cache.
    * @return object statement
    */
   public static function executePrepared($obj,$values=array()) {
      if(!self::$loaded)
         self::loadColumns();

      if(!is_string($obj) && get_class($obj)=="TORM\Builder")
         $sql = $obj->toString();
      if(is_string($obj))
         $sql = $obj;

      $stmt = self::putCache($sql);
      $stmt->execute($values);
      return $stmt;
   }

   public static function query($sql) {
      return self::resolveConnection()->query($sql);
   }

   /**
    * Add an error to an attribute
    * @param $attr attribute
    * @param $msg  message
    */
   private function addError($attr,$msg) {
      if(!array_key_exists($attr,$this->errors))
         $this->errors[$attr] = array();
      array_push($this->errors[$attr],$msg);
   }

   /**
    * Reset errors
    */
   private function resetErrors() {
      $this->errors = array();
   }

   /**
    * Check if is valid
    */
   public function isValid() {
      $this->resetErrors();
      $cls = get_called_class();
      $rtn = true;
      $pk  = self::get(self::getPK());

      if(sizeof(self::$validations[$cls])<1)
         return;

      foreach(self::$validations[$cls] as $attr=>$validations) {
         $value = $this->data[$attr];

         foreach($validations as $validation) {
            $validation_key   = array_keys($validation);
            $validation_key   = $validation_key[0];
            $validation_value = array_values($validation);
            $validation_value = $validation_value[0];
            $args = array(get_called_class(),$pk,$attr,$value,$validation_value,$validation);
            $test = call_user_func_array(array("TORM\Validation",$validation_key),$args);
            if(!$test) {
               $rtn = false;
               $this->addError($attr,Validation::$validation_map[$validation_key]);
            }
         }
      }
      return $rtn;
   }

   /**
    * Check if attribute is unique
    * @param object attribute
    * @return if attribute is unique
    */
   public static function isUnique($id,$attr,$attr_value) {
      $obj = self::first(array($attr=>$attr_value));
      return $obj==null || $obj->get(self::getPK())==$id;
   }

   public function get($attr) {
      if(!$this->data || !array_key_exists($attr,$this->data))
         return null;
      return $this->data[$attr];
   }

   public function set($attr,$value) {
      $pk = self::getPK();
      // can't change the primary key of an existing record
      if(!$this->new_rec && $attr==$pk)
         return;
      $this->data[$attr] = $value;
   }

   public static function validates($attr,$validation) {
      $cls = get_called_class();

      // bummer! need to verify the calling class
      if(!array_key_exists($cls,self::$validations))
         self::$validations[$cls] = array();

      if(!array_key_exists($attr,self::$validations[$cls]))
         self::$validations[$cls][$attr] = array();

      array_push(self::$validations[$cls][$attr],$validation);
   }

   /**
    * Create a has many relationship
    * @param $attr attribute
    */
   public static function hasMany($attr,$options=null) {
      $cls = get_called_class();
      if(!array_key_exists($cls,self::$has_many))
         self::$has_many[$cls] = array();
      self::$has_many[$cls][$attr] = $options ? $options : false;
   }

   /**
    * Check a has many relationship and returns it resolved, if exists.
    * @param $method name
    * @param $value  
    * @return has many collection, if any
    */
   private static function checkAndReturnMany($method,$value) {
      $cls = get_called_class();
      if(array_key_exists($cls   ,self::$has_many) &&
         array_key_exists($method,self::$has_many[$cls]))
         return self::resolveHasMany($method,$value);
   }

   /**
    * Resolve the has many relationship and returns the collection with values
    * @param $attr name
    * @param $value
    * @return collection
    */
   private static function resolveHasMany($attr,$value) {
      $cls = get_called_class();
      if(!array_key_exists($cls,self::$has_many) ||
         !array_key_exists($attr,self::$has_many[$cls]))
         return null;

      $configs       = self::$has_many[$cls][$attr];
      $has_many_cls  = is_array($configs) && array_key_exists("class_name",$configs) ? $configs["class_name"] : ucfirst(preg_replace('/s$/',"",$attr));
      $this_key      = self::isIgnoringCase() ? strtolower($cls)."_id" : $cls."_id";
      $collection    = $has_many_cls::where(array($this_key=>$value));
      return $collection;
   }

   /**
    * Create a belongs relationship
    * @param $attr attribute
    * @param $options options for relation
    */
   public static function belongsTo($model,$options=null) {
      $cls = get_called_class();
      if(!array_key_exists($cls,self::$belongs_to))
         self::$belongs_to[$cls] = array();
      self::$belongs_to[$cls][$model] = $options ? $options : false;
   }

   private static function checkAndReturnBelongs($method,$value) {
      $cls = get_called_class();
      if(array_key_exists($cls   ,self::$belongs_to) &&
         array_key_exists($method,self::$belongs_to[$cls]))
         return self::resolveBelongsTo($method,$value);
   }

   private static function resolveBelongsTo($attr,$value) {
      $cls = get_called_class();
      if(!array_key_exists($cls,self::$belongs_to) ||
         !array_key_exists($attr,self::$belongs_to[$cls]))
         return null;

      $configs       = self::$belongs_to[$cls][$attr];
      $belongs_cls   = is_array($configs) && array_key_exists("class_name",$configs) ? $configs["class_name"] : ucfirst($attr);
      $obj           = $belongs_cls::first(array("id"=>$value));
      return $obj;
   }

   public function updateAttributes($attrs) {
      foreach($attrs as $attr=>$value) 
         $this->data[$attr] = $value;
      $this->save();
   }

   /**
    * Set the sequence name, if any
    * @param $name of the sequence
    */
   public static function setSequenceName($name) {
      $cls = get_called_class();
      self::$sequence[$cls] = $name;
   }

   /**
    * Returns the sequence name, if any
    * @return $name of the sequence
    */
   public static function getSequenceName() {
      $cls = get_called_class();
      if(!array_key_exists($cls,self::$sequence))
         return null;
      return self::$sequence[$cls];
   }

   /**
    * Resolve the sequence name, if any
    * @returns $name of the sequence
    */
   public static function resolveSequenceName() {
      if(Driver::$primary_key_behaviour!=Driver::PRIMARY_KEY_SEQUENCE)
         return null;

      $suffix  = "_sequence";
      $table   = strtolower(self::getTableName());
      $name    = self::getSequenceName();
      if($name) 
         return $name;
      return $table.$suffix;
   }

   private static function sequenceExists() {
      if(Driver::$primary_key_behaviour!=Driver::PRIMARY_KEY_SEQUENCE)
         return null;

      $name = self::resolveSequenceName();
      $sql  = "select count(*) as $escape"."CNT"."$escape from user_sequences where sequence_name='$name' or sequence_name='".strtolower($name)."' or sequence_name='".strtoupper($name)."'";
      $stmt = self::resolveConnection()->query($sql);
      $rst  = $stmt->fetch(\PDO::FETCH_ASSOC);
      return intval($rst["CNT"])>0;
   }

   /**
    * Check if sequence exists
    * If not, create it.
    */
   private static function checkSequence() {
      if(Driver::$primary_key_behaviour!=Driver::PRIMARY_KEY_SEQUENCE)
         return null;

      if(self::sequenceExists())
         return;

      // create sequence
      $name = self::resolveSequenceName();
      $sql  = "create sequence $name increment by 1 start with 1 nocycle nocache";
      Log::log($sql);
      $stmt = self::resolveConnection()->query($sql);
   }

   /**
    * Put a prepared statement on cache, if not there.
    * @return object prepared statement
    */
   public static function putCache($sql) {
      $md5 = md5($sql);
      if(array_key_exists($md5,self::$prepared_cache)) {
         Log::log("already prepared: $sql");
         return self::$prepared_cache[$md5];
      } else {
         Log::log("inserting on cache: $sql");
      }
      $prepared = self::resolveConnection()->prepare($sql);
      self::$prepared_cache[$md5] = $prepared;
      return $prepared;
   }

   /**
    * Get a prepared statement from cache
    * @return object or null if not on cache
    */
   public static function getCache($sql) {
      $md5 = md5($sql);
      if(!array_key_exists($md5,self::$prepared_cache)) 
         return null;
      return self::$prepared_cache[$md5];
   }

   /**
    * Trigger to use object values as methods
    * Like
    * $user->name("john doe");
    * print $user->name();
    */
   public function __call($method,$args) {
      if(method_exists($this,$method)) 
         return call_user_func_array(array($this,$method),$args);

      $many = self::checkAndReturnMany($method,$this->data[self::getPK()]);
      if($many)
         return $many;

      $belongs = self::checkAndReturnBelongs($method,$this->data[self::getPK()]);
      if($belongs)
         return $belongs;

      if(!$args) 
         return $this->data[$method];
      $this->set($method,$args[0]);
   }

   /**
    * Trigger to get object values as attributes
    * Like
    * print $user->name;
    */
   function __get($attr) {
      $many = self::checkAndReturnMany($attr,$this->data[self::getPK()]);
      if($many)
         return $many;

      $belongs = self::checkAndReturnBelongs($attr,$this->data[self::getPK()]);
      if($belongs)
         return $belongs;

      return $this->get($attr);
   }

   /**
    * Trigger to set object values as attributes
    * Like
    * $user->name = "john doe";
    */
   function __set($attr,$value) {
      $this->set($attr,$value);
   }
}
?>
