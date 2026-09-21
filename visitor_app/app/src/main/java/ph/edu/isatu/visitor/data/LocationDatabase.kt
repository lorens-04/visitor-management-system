package ph.edu.isatu.visitor.data

import android.content.Context
import androidx.room.Dao
import androidx.room.Database
import androidx.room.Entity
import androidx.room.Index
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.PrimaryKey
import androidx.room.Query
import androidx.room.Room
import androidx.room.RoomDatabase

@Entity(
    tableName = "queued_locations",
    indices = [Index(value = ["clientEventId"], unique = true)],
)
data class QueuedLocation(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val appointmentId: Long,
    val trackingSessionId: Long,
    val clientEventId: String,
    val latitude: Double,
    val longitude: Double,
    val accuracy: Double?,
    val capturedAt: String,
)

@Dao
interface LocationQueueDao {
    @Insert(onConflict = OnConflictStrategy.IGNORE)
    suspend fun insert(point: QueuedLocation): Long

    @Query("SELECT * FROM queued_locations WHERE trackingSessionId = :sessionId ORDER BY id ASC LIMIT :limit")
    suspend fun oldest(sessionId: Long, limit: Int): List<QueuedLocation>

    @Query("DELETE FROM queued_locations WHERE id IN (:ids)")
    suspend fun delete(ids: List<Long>)

    @Query("SELECT COUNT(*) FROM queued_locations WHERE trackingSessionId = :sessionId")
    suspend fun count(sessionId: Long): Int

    @Query("DELETE FROM queued_locations WHERE appointmentId = :appointmentId")
    suspend fun deleteForAppointment(appointmentId: Long)
}

@Database(entities = [QueuedLocation::class], version = 1, exportSchema = true)
abstract class VisitorDatabase : RoomDatabase() {
    abstract fun locationQueue(): LocationQueueDao

    companion object {
        @Volatile private var instance: VisitorDatabase? = null

        fun get(context: Context): VisitorDatabase = instance ?: synchronized(this) {
            instance ?: Room.databaseBuilder(
                context.applicationContext,
                VisitorDatabase::class.java,
                "isatu_visitor.db",
            ).build().also { instance = it }
        }
    }
}

