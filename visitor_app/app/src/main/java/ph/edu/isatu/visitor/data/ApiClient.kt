package ph.edu.isatu.visitor.data

import com.google.gson.Gson
import com.google.gson.GsonBuilder
import com.google.gson.reflect.TypeToken
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import ph.edu.isatu.visitor.BuildConfig
import retrofit2.Response
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

class ApiException(
    val statusCode: Int,
    override val message: String,
    val fieldErrors: Map<String, String> = emptyMap(),
) : Exception(message)

class ApiClient(private val tokenStore: SecureTokenStore) {
    val gson: Gson = GsonBuilder()
        .setFieldNamingPolicy(com.google.gson.FieldNamingPolicy.LOWER_CASE_WITH_UNDERSCORES)
        .create()

    private val authInterceptor = Interceptor { chain ->
        val original = chain.request()
        val builder = original.newBuilder()
            .header("Accept", "application/json")
            .header("User-Agent", "ISATU-Visitor-Android/${BuildConfig.VERSION_NAME}")
        tokenStore.readToken()?.let { builder.header("Authorization", "Bearer $it") }
        val response = chain.proceed(builder.build())
        if (response.code == 401 && !original.url.encodedPath.endsWith("/auth/login.php")) {
            tokenStore.clear()
        }
        response
    }

    private val client = OkHttpClient.Builder()
        .addInterceptor(authInterceptor)
        .apply {
            if (BuildConfig.DEBUG) {
                addInterceptor(HttpLoggingInterceptor().apply {
                    level = HttpLoggingInterceptor.Level.BASIC
                    redactHeader("Authorization")
                })
            }
        }
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .writeTimeout(30, TimeUnit.SECONDS)
        .build()

    val api: VisitorApi = Retrofit.Builder()
        .baseUrl(BuildConfig.API_BASE_URL)
        .client(client)
        .addConverterFactory(GsonConverterFactory.create(gson))
        .build()
        .create(VisitorApi::class.java)

    fun <T> requireData(response: Response<ApiEnvelope<T>>): T {
        val body = response.body()
        if (response.isSuccessful && body?.success == true && body.data != null) return body.data
        val errorEnvelope = response.errorBody()?.string()?.let { raw ->
            runCatching {
                val type = object : TypeToken<ApiEnvelope<Any?>>() {}.type
                gson.fromJson<ApiEnvelope<Any?>>(raw, type)
            }.getOrNull()
        }
        val fieldErrors = errorEnvelope?.errors.orEmpty().mapValues { (_, value) -> value?.toString().orEmpty() }
        throw ApiException(
            statusCode = response.code(),
            message = errorEnvelope?.message ?: body?.message ?: "The server could not complete the request.",
            fieldErrors = fieldErrors,
        )
    }
}

