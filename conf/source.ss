source BaseSrc  {
	type = mysql
	sql_host = $Database.server
	sql_user = $Database.username
	sql_pass = $Database.password
	sql_db = $Database.database
	sql_port = 3306
	
	sql_query_pre = SET NAMES utf8
}