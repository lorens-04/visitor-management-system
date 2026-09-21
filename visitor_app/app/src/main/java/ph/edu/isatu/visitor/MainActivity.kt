package ph.edu.isatu.visitor

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.lifecycle.ViewModelProvider
import ph.edu.isatu.visitor.ui.AppViewModel
import ph.edu.isatu.visitor.ui.ISATUVisitorTheme
import ph.edu.isatu.visitor.ui.VisitorApp

class MainActivity : ComponentActivity() {
    private lateinit var viewModel: AppViewModel

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        viewModel = ViewModelProvider(this, AppViewModel.Factory(application))[AppViewModel::class.java]
        setContent {
            ISATUVisitorTheme {
                VisitorApp(viewModel)
            }
        }
        openAppointmentFrom(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        openAppointmentFrom(intent)
    }

    private fun openAppointmentFrom(intent: Intent?) {
        intent?.getLongExtra("appointment_id", 0L)?.takeIf { it > 0 }?.let(viewModel::openAppointment)
    }
}

