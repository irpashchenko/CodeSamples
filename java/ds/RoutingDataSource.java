/**
 * Public {@link javax.sql.DataSource} implementation that routes {@link #getConnection()}
 * calls to one of various target DataSources based on a lookup key.
 */
public class RoutingDataSource extends AbstractDataSource {

    private static final Logger LOGGER = getLogger(RoutingDataSource.class);

    private int blockMasterAfterFailCount = 3;
    private long timeBeforeRetryAfterMasterFailsMillis = 120000;

    private static long masterBlockUntilTimestamp = 0;
    private Map<DBType, DataSource> targetDataSources = new HashMap<>();
    private AtomicInteger masterFailCount = new AtomicInteger();

    public static boolean isMasterDataSourceBlocked() {
        return masterBlockUntilTimestamp > System.currentTimeMillis();
    }

    @Override
    @SuppressWarnings("JDBCResourceOpenedButNotSafelyClosed") // wrapper for data source may not close resources properly
    public Connection getConnection() throws SQLException {
        Connection connection;
        boolean connectionFromSlave = false;
        DataSource routeDataSource = targetDataSources.get(DbContextHolder.getDbType());
        AssertUtils.notNull(routeDataSource, "Route " + DbContextHolder.getDbType() + " is not available, please make sure RoutingDataSource configuration is correct");
        if (DbContextHolder.isFallbackDatasourceAllowed()) {
            try {
                if (isMasterDataSourceBlocked()) {
                    LOGGER.warn("Master data source is blocked after fail, getting connection from the slave data source");
                    connectionFromSlave = true;
                    connection = targetDataSources.get(DBType.SLAVE).getConnection();
                } else {
                    LOGGER.debug("Returning connection from the config data source");
                    connection = routeDataSource.getConnection();
                }
            } catch (SQLException | RuntimeException e) {
                if (!(e instanceof SQLException || e.getClass().getSimpleName().equals("PoolInitializationException"))) {
                    throw e;
                }
                if (connectionFromSlave) {
                    LOGGER.error("Caught exception on slave data source: {}, rethrowing", "reader", e);
                    throw e;
                }
                int failCount = masterFailCount.incrementAndGet();
                if (blockMasterAfterFailCount > 0 && failCount >= blockMasterAfterFailCount) {
                    masterFailCount.set(0);
                    masterBlockUntilTimestamp = System.currentTimeMillis() + timeBeforeRetryAfterMasterFailsMillis;
                    String untilDate = ValueConstants.dateTimeFormatter.format(LocalDateTime.ofInstant(Instant.ofEpochMilli(masterBlockUntilTimestamp), ZoneId.systemDefault()));
                    LOGGER.warn("Master data source is blocked to connect until {}, connection will be taken from the slave", untilDate);
                }

                LOGGER.warn("Caught exception on master data source: {}, trying to get connection from slave data source", DbContextHolder.getDbType(), e);
                try {
                    connection = targetDataSources.get(DBType.SLAVE).getConnection();
                } catch (SQLException e1) {
                    LOGGER.error("Caught exception on slave data source: reader, rethrowing top exception", e1);
                    throw e;
                }
            }
        } else {
            connection = routeDataSource.getConnection();
        }
        return connection;
    }

    @Override
    public Connection getConnection(String username, String password) throws SQLException {
        return targetDataSources.get(DbContextHolder.getDbType()).getConnection(username, password);
    }

    public void addRoute(DBType route, DataSource dataSource) {
        targetDataSources.put(route, dataSource);
    }

    public void setBlockMasterAfterFailCount(int blockMasterAfterFailCount) {
        this.blockMasterAfterFailCount = blockMasterAfterFailCount;
    }

    public void setTimeBeforeRetryAfterMasterFailsMillis(long timeBeforeRetryAfterMasterFailsMillis) {
        this.timeBeforeRetryAfterMasterFailsMillis = timeBeforeRetryAfterMasterFailsMillis;
    }
}