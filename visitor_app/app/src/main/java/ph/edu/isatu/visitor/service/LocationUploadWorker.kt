package ph.edu.isatu.visitor.service

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.Data
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import ph.edu.isatu.visitor.VisitorApplication
import ph.edu.isatu.visitor.data.ApiException

class LocationUploadWorker(
    appContext: Context,
    params: WorkerParameters,
) : CoroutineWorker(appContext, params) {
    override suspend fun doWork(): Result {
        val sessionId = inputData.getLong(KEY_SESSION_ID, 0)
        if (sessionId <= 0) return Result.failure()
        val repository = (applicationContext as VisitorApplication).repository
        return try {
            repository.flushLocations(sessionId)
            Result.success()
        } catch (error: ApiException) {
            if (error.statusCode in listOf(401, 403, 404, 409)) Result.failure()
            else if (runAttemptCount < 8) Result.retry() else Result.failure()
        } catch (_: Exception) {
            if (runAttemptCount < 8) Result.retry() else Result.failure()
        }
    }

    companion object {
        private const val KEY_SESSION_ID = "tracking_session_id"

        fun enqueue(context: Context, sessionId: Long) {
            val request = OneTimeWorkRequestBuilder<LocationUploadWorker>()
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setInputData(Data.Builder().putLong(KEY_SESSION_ID, sessionId).build())
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork(
                "upload-visitor-locations-$sessionId",
                ExistingWorkPolicy.KEEP,
                request,
            )
        }
    }
}

