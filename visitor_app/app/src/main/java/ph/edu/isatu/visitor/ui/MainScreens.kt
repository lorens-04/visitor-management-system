package ph.edu.isatu.visitor.ui

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Logout
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.MarkEmailRead
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.PendingActions
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Security
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import ph.edu.isatu.visitor.data.AppointmentDto
import ph.edu.isatu.visitor.data.NotificationDto
import java.text.SimpleDateFormat
import java.util.Locale

private data class MainTab(val label: String, val icon: ImageVector)

private val tabs = listOf(
    MainTab("Home", Icons.Rounded.Home),
    MainTab("Visits", Icons.Rounded.CalendarMonth),
    MainTab("Book", Icons.Rounded.Add),
)

private enum class HeaderScreen { Notifications, Profile }

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun MainShell(state: VisitorUiState, viewModel: AppViewModel) {
    var selectedTab by remember { mutableIntStateOf(0) }
    var headerScreen by remember { mutableStateOf<HeaderScreen?>(null) }
    state.selectedAppointment?.let { appointment ->
        AppointmentDetailScreen(appointment, state.tracking, state.busy, viewModel)
        return
    }

    Scaffold(
        containerColor = AppBackground,
        topBar = {
            Column {
                TopAppBar(
                    title = { PortalBrand(subtitle = "Visitor mobile application") },
                    actions = {
                        IconButton(onClick = { headerScreen = HeaderScreen.Notifications }) {
                            BadgedBox(
                                badge = {
                                    if (state.unreadCount > 0) {
                                        Badge(containerColor = Color(0xFFD92D20)) {
                                            Text(state.unreadCount.coerceAtMost(99).toString())
                                        }
                                    }
                                },
                            ) {
                                Icon(Icons.Rounded.Notifications, contentDescription = "Notifications", tint = IsatuBlueDark)
                            }
                        }
                        IconButton(onClick = { headerScreen = HeaderScreen.Profile }) {
                            Icon(Icons.Rounded.Person, contentDescription = "Profile", tint = IsatuBlueDark)
                        }
                    },
                    colors = TopAppBarDefaults.topAppBarColors(containerColor = Color.White),
                )
                if (state.busy) LinearProgressIndicator(Modifier.fillMaxWidth(), color = IsatuBlue)
            }
        },
        bottomBar = {
            NavigationBar(containerColor = Color.White, tonalElevation = 8.dp) {
                tabs.forEachIndexed { index, tab ->
                    NavigationBarItem(
                        selected = headerScreen == null && selectedTab == index,
                        onClick = {
                            selectedTab = index
                            headerScreen = null
                        },
                        icon = { Icon(tab.icon, contentDescription = tab.label) },
                        label = { Text(tab.label) },
                        colors = NavigationBarItemDefaults.colors(
                            selectedIconColor = IsatuBlue,
                            selectedTextColor = IsatuBlueDark,
                            indicatorColor = BlueSurface,
                            unselectedIconColor = MutedInk,
                            unselectedTextColor = MutedInk,
                        ),
                    )
                }
            }
        },
    ) { innerPadding ->
        Column(Modifier.fillMaxSize().padding(innerPadding)) {
            if (state.error != null || state.message != null) {
                Box(Modifier.padding(horizontal = 16.dp, vertical = 8.dp)) {
                    MessageCard(
                        message = state.error ?: state.message.orEmpty(),
                        error = state.error != null,
                        onDismiss = viewModel::clearBanner,
                    )
                }
            }
            when (headerScreen) {
                HeaderScreen.Notifications -> NotificationsScreen(state.notifications, state.unreadCount, viewModel)
                HeaderScreen.Profile -> ProfileScreen(state, viewModel)
                null -> when (selectedTab) {
                    0 -> HomeScreen(
                        state = state,
                        viewModel = viewModel,
                        onBook = { selectedTab = 2 },
                        onAppointments = { selectedTab = 1 },
                    )
                    1 -> AppointmentsScreen(state.appointments, viewModel::openAppointment, viewModel::refreshAll)
                    else -> BookingScreen(state, viewModel)
                }
            }
        }
    }
}

@Composable
private fun HomeScreen(
    state: VisitorUiState,
    viewModel: AppViewModel,
    onBook: () -> Unit,
    onAppointments: () -> Unit,
) {
    val context = LocalContext.current
    var notificationsAllowed by remember {
        mutableStateOf(
            Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
                ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED,
        )
    }
    val notificationPermissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { granted -> notificationsAllowed = granted }

    val activeStatuses = setOf("pending_approval", "approved", "reschedule_proposed", "checked_in")
    val active = state.appointments.filter { it.status in activeStatuses }.sortedBy { it.scheduledStartAt }
    val upcoming = active.firstOrNull()
    val firstName = state.user?.fullName?.trim()?.substringBefore(' ')?.ifBlank { "Visitor" } ?: "Visitor"

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(start = 18.dp, end = 18.dp, top = 18.dp, bottom = 28.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        item {
            Card(
                colors = CardDefaults.cardColors(containerColor = IsatuBlueDark),
                shape = RoundedCornerShape(22.dp),
            ) {
                Column(Modifier.fillMaxWidth().padding(22.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("Welcome, $firstName", style = MaterialTheme.typography.headlineMedium, color = Color.White)
                    Text(
                        "Plan your visit, receive office decisions, and keep your visitor pass in one place.",
                        color = Color(0xFFDCEBFF),
                    )
                    Spacer(Modifier.height(6.dp))
                    PrimaryButton(
                        text = "Register a visit",
                        onClick = onBook,
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
            }
        }

        item {
            SectionHeading("Quick actions")
            Spacer(Modifier.height(10.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                QuickActionCard("Register visit", "Walk in or schedule ahead", Icons.Rounded.Add, onBook, Modifier.weight(1f))
                QuickActionCard("My visits", "View requests and QR passes", Icons.Rounded.QrCode2, onAppointments, Modifier.weight(1f))
            }
        }

        if (!notificationsAllowed) {
            item {
                Card(
                    colors = CardDefaults.cardColors(containerColor = YellowSurface),
                    shape = RoundedCornerShape(18.dp),
                    border = BorderStroke(1.dp, Color(0xFFF4D86C)),
                ) {
                    Row(Modifier.padding(18.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        Icon(Icons.Rounded.Notifications, contentDescription = null, tint = Color(0xFF9A6700))
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            Text("Turn on appointment updates", style = MaterialTheme.typography.titleMedium)
                            Text(
                                "Get notified when an office approves, declines, or suggests another schedule.",
                                color = MutedInk,
                            )
                            TextButton(
                                onClick = {
                                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                                        notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
                                    }
                                },
                            ) {
                                Text("Enable notifications")
                            }
                        }
                    }
                }
            }
        }

        item {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                SectionHeading("Next visit", Modifier.weight(1f))
                TextButton(onClick = onAppointments) { Text("View all") }
            }
            Spacer(Modifier.height(8.dp))
            if (upcoming == null) {
                EmptyState("No upcoming visit", "Request an appointment when you are ready to visit the campus.")
            } else {
                AppointmentCard(upcoming) { viewModel.openAppointment(upcoming.id) }
            }
        }

        item {
            Card(
                colors = CardDefaults.cardColors(containerColor = Color.White),
                border = BorderStroke(1.dp, BorderSoft),
                shape = RoundedCornerShape(18.dp),
            ) {
                Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Text("How your visit works", style = MaterialTheme.typography.titleLarge, color = IsatuBlueDark)
                    VisitStep(Icons.Rounded.PendingActions, "1", "Register", "Choose walk-in or appointment and tell us where you are going.")
                    VisitStep(Icons.Rounded.CheckCircle, "2", "Get your pass", "Walk-ins receive it now; appointments receive it after approval.")
                    VisitStep(Icons.Rounded.QrCode2, "3", "Present your pass", "An approved visit receives a secure QR pass.")
                    VisitStep(Icons.Rounded.LocationOn, "4", "Check in", "Location sharing starts only after Security check-in and your permission.")
                }
            }
        }
    }
}

@Composable
private fun SectionHeading(text: String, modifier: Modifier = Modifier) {
    Text(text, style = MaterialTheme.typography.titleLarge, color = Ink, modifier = modifier)
}

@Composable
private fun SummaryCard(
    value: String,
    label: String,
    icon: ImageVector,
    color: Color,
    modifier: Modifier = Modifier,
) {
    Card(
        modifier = modifier,
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(18.dp),
    ) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Box(Modifier.size(38.dp).clip(CircleShape).background(color.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(icon, contentDescription = null, tint = color, modifier = Modifier.size(21.dp))
            }
            Text(value, style = MaterialTheme.typography.headlineMedium, color = color)
            Text(label, color = MutedInk, style = MaterialTheme.typography.bodyMedium)
        }
    }
}

@Composable
private fun QuickActionCard(
    title: String,
    body: String,
    icon: ImageVector,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Card(
        modifier = modifier.clickable(onClick = onClick),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(18.dp),
    ) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(icon, contentDescription = null, tint = IsatuBlue, modifier = Modifier.size(28.dp))
            Text(title, style = MaterialTheme.typography.titleMedium)
            Text(body, color = MutedInk, style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun VisitStep(icon: ImageVector, number: String, title: String, body: String) {
    Row(horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.Top) {
        Box(Modifier.size(38.dp).clip(CircleShape).background(BlueSurface), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = "Step $number", tint = IsatuBlue, modifier = Modifier.size(21.dp))
        }
        Column(Modifier.weight(1f)) {
            Text("$number. $title", fontWeight = FontWeight.SemiBold)
            Text(body, color = MutedInk, style = MaterialTheme.typography.bodyMedium)
        }
    }
}

@Composable
private fun AppointmentsScreen(
    appointments: List<AppointmentDto>,
    onOpen: (Long) -> Unit,
    onRefresh: () -> Unit,
) {
    var selectedFilter by remember { mutableStateOf("All") }
    val filters = listOf("All", "Active", "History")
    val visible = appointments.filter {
        when (selectedFilter) {
            "Active" -> it.status in listOf("pending_approval", "approved", "reschedule_proposed", "checked_in")
            "History" -> it.status in listOf("completed", "window_closed", "rejected", "cancelled", "unanswered")
            else -> true
        }
    }

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(18.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("My visits", style = MaterialTheme.typography.headlineMedium, color = IsatuBlueDark)
                    Text("Requests, visitor passes, and visit history", color = MutedInk)
                }
                IconButton(onClick = onRefresh) { Icon(Icons.Rounded.Refresh, "Refresh") }
            }
        }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                filters.forEach { filter ->
                    if (selectedFilter == filter) {
                        FilledTonalButton(onClick = { selectedFilter = filter }) { Text(filter) }
                    } else {
                        OutlinedButton(onClick = { selectedFilter = filter }) { Text(filter) }
                    }
                }
            }
        }
        if (visible.isEmpty()) {
            item { EmptyState("Nothing here yet", "Visits matching this filter will appear here.") }
        } else {
            items(visible, key = { it.id }) { AppointmentCard(it) { onOpen(it.id) } }
        }
    }
}

@Composable
fun AppointmentCard(appointment: AppointmentDto, onClick: () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth().clickable(onClick = onClick),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(18.dp),
    ) {
        Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(42.dp).clip(RoundedCornerShape(12.dp)).background(BlueSurface), contentAlignment = Alignment.Center) {
                    Icon(
                        if (appointment.qrPass != null) Icons.Rounded.QrCode2 else Icons.Rounded.CalendarMonth,
                        contentDescription = null,
                        tint = IsatuBlue,
                    )
                }
                Spacer(Modifier.size(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(appointment.office.name, style = MaterialTheme.typography.titleMedium)
                    Text(
                        "${if (appointment.visitType == "walk_in") "Walk-in" else "Appointment"} • ${formatDateTime(appointment.scheduledStartAt)}",
                        color = MutedInk,
                        style = MaterialTheme.typography.bodyMedium,
                    )
                }
                Icon(Icons.Rounded.ChevronRight, contentDescription = "Open visit", tint = MutedInk)
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(appointment.subject, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                Spacer(Modifier.size(8.dp))
                StatusPill(appointment.status)
            }
            appointment.registrationCode?.let {
                Text("Reference $it", color = IsatuBlue, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@Composable
private fun NotificationsScreen(
    notifications: List<NotificationDto>,
    unreadCount: Int,
    viewModel: AppViewModel,
) {
    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(18.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("Notifications", style = MaterialTheme.typography.headlineMedium, color = IsatuBlueDark)
                    Text(if (unreadCount == 0) "You are all caught up" else "$unreadCount unread notification(s)", color = MutedInk)
                }
                if (unreadCount > 0) TextButton(onClick = viewModel::markAllNotificationsRead) { Text("Mark all read") }
            }
        }
        if (notifications.isEmpty()) {
            item { EmptyState("No notifications yet", "Office decisions, schedule changes, and visit reminders will appear here.") }
        } else {
            items(notifications, key = { it.id }) { notification ->
                NotificationCard(notification) {
                    if (notification.readAt == null) viewModel.markNotificationRead(notification.id)
                    notification.appointmentId?.let(viewModel::openAppointment)
                }
            }
        }
    }
}

@Composable
private fun NotificationCard(notification: NotificationDto, onClick: () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth().clickable(onClick = onClick),
        colors = CardDefaults.cardColors(containerColor = if (notification.readAt == null) BlueSurface else Color.White),
        border = BorderStroke(1.dp, if (notification.readAt == null) Color(0xFFB9D4FF) else BorderSoft),
        shape = RoundedCornerShape(16.dp),
    ) {
        Row(Modifier.padding(16.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(
                Modifier.size(40.dp).clip(CircleShape).background(if (notification.readAt == null) IsatuBlue else Color(0xFFE8EEF7)),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    Icons.Rounded.MarkEmailRead,
                    contentDescription = null,
                    tint = if (notification.readAt == null) Color.White else MutedInk,
                    modifier = Modifier.size(21.dp),
                )
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(notification.title, fontWeight = FontWeight.SemiBold)
                Text(notification.message, color = MutedInk)
                Text(formatDateTime(notification.createdAt), style = MaterialTheme.typography.bodySmall, color = MutedInk)
            }
            if (notification.readAt == null) {
                Box(Modifier.size(8.dp).clip(CircleShape).background(IsatuBlue))
            }
        }
    }
}

@Composable
private fun ProfileScreen(state: VisitorUiState, viewModel: AppViewModel) {
    var showEdit by remember { mutableStateOf(false) }
    val user = state.user
    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(18.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        item {
            Text("My profile", style = MaterialTheme.typography.headlineMedium, color = IsatuBlueDark)
            Text("Personal details and privacy controls", color = MutedInk)
        }
        item {
            Card(
                colors = CardDefaults.cardColors(containerColor = Color.White),
                border = BorderStroke(1.dp, BorderSoft),
                shape = RoundedCornerShape(20.dp),
            ) {
                Column(Modifier.fillMaxWidth().padding(22.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    Box(
                        Modifier.size(78.dp).clip(CircleShape).background(BlueSurface),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text(
                            user?.fullName?.trim()?.firstOrNull()?.uppercase() ?: "V",
                            style = MaterialTheme.typography.headlineLarge,
                            color = IsatuBlue,
                        )
                    }
                    Spacer(Modifier.height(12.dp))
                    Text(user?.fullName.orEmpty(), style = MaterialTheme.typography.titleLarge)
                    Text(user?.email.orEmpty(), color = MutedInk)
                    Text(user?.contactNumber?.ifBlank { "No contact number" }.orEmpty(), color = MutedInk)
                    Spacer(Modifier.height(14.dp))
                    FilledTonalButton(onClick = { showEdit = true }) {
                        Icon(Icons.Rounded.Edit, contentDescription = null)
                        Spacer(Modifier.size(8.dp))
                        Text("Edit profile")
                    }
                }
            }
        }
        item {
            Card(
                colors = CardDefaults.cardColors(containerColor = Color.White),
                border = BorderStroke(1.dp, BorderSoft),
                shape = RoundedCornerShape(18.dp),
            ) {
                Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.Security, contentDescription = null, tint = IsatuBlue)
                        Spacer(Modifier.size(10.dp))
                        Text("Privacy and location", style = MaterialTheme.typography.titleMedium)
                    }
                    Text(
                        "Location is collected only after Security checks you in and while the tracking notification is visible. You can withdraw consent from the active visit.",
                        color = MutedInk,
                    )
                }
            }
        }
        item {
            OutlinedButton(
                onClick = viewModel::logout,
                modifier = Modifier.fillMaxWidth().height(52.dp),
                enabled = !state.busy,
            ) {
                Icon(Icons.AutoMirrored.Rounded.Logout, contentDescription = null)
                Spacer(Modifier.size(8.dp))
                Text("Sign out")
            }
        }
    }
    if (showEdit && user != null) {
        EditProfileDialog(user.fullName, user.contactNumber, { showEdit = false }) { name, contact ->
            viewModel.updateProfile(name, contact)
            showEdit = false
        }
    }
}

@Composable
private fun EditProfileDialog(
    currentName: String,
    currentContact: String,
    onDismiss: () -> Unit,
    onSave: (String, String) -> Unit,
) {
    var name by remember { mutableStateOf(currentName) }
    var contact by remember { mutableStateOf(currentContact) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Edit profile") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                OutlinedTextField(name, { name = it }, label = { Text("Full name") }, singleLine = true)
                OutlinedTextField(contact, { contact = it }, label = { Text("Contact number") }, singleLine = true)
            }
        },
        confirmButton = {
            TextButton(onClick = { onSave(name, contact) }, enabled = name.trim().length >= 2) { Text("Save") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

fun formatDateTime(value: String): String {
    if (value.isBlank()) return "Schedule not available"
    return runCatching {
        val source = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
        val target = SimpleDateFormat("MMM d, yyyy • h:mm a", Locale.US)
        target.format(requireNotNull(source.parse(value)))
    }.getOrDefault(value)
}
