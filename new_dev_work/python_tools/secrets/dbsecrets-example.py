config = {
  'user': 'USERNAME',
  'password': 'PASSWORD',
  'host': '127.0.0.1', # localhost because we will port-forward
  'database': 'wordpress',
  'port': 3306
}

#Modified config for working within pod environment instead of port-forwarding
DB_CONFIG = {
    'host': 'wordpress-mysql.default.svc.cluster.local',
    'user': 'USERNAME',
    'password': 'PASSWORD',
    'database': 'wordpress',
    'charset': 'utf8mb4'
}