package ph.edu.isatu.visitor

import android.app.Application
import ph.edu.isatu.visitor.data.ApiClient
import ph.edu.isatu.visitor.data.InstallationStore
import ph.edu.isatu.visitor.data.SecureTokenStore
import ph.edu.isatu.visitor.data.VisitorDatabase
import ph.edu.isatu.visitor.data.VisitorRepository

class VisitorApplication : Application() {
    lateinit var repository: VisitorRepository
        private set

    override fun onCreate() {
        super.onCreate()
        val tokenStore = SecureTokenStore(this)
        val installationStore = InstallationStore(this)
        repository = VisitorRepository(
            apiClient = ApiClient(tokenStore),
            tokenStore = tokenStore,
            installationStore = installationStore,
            locationQueue = VisitorDatabase.get(this).locationQueue(),
        )
    }
}

