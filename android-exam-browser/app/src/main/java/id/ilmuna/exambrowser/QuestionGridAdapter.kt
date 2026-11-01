package id.ilmuna.exambrowser

import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView

class QuestionGridAdapter(
    private val questions: List<QuestionItem>,
    private var currentIndex: Int,
    private val onItemClick: (Int) -> Unit
) : RecyclerView.Adapter<QuestionGridAdapter.ViewHolder>() {

    class ViewHolder(itemView: View) : RecyclerView.ViewHolder(itemView) {
        val tvNumber: TextView = itemView.findViewById(R.id.tvGridNumber)
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val view = LayoutInflater.from(parent.context).inflate(R.layout.item_grid_number, parent, false)
        return ViewHolder(view)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) {
        val q = questions[position]
        val context = holder.itemView.context

        holder.tvNumber.text = q.nomorUrut.toString()

        val isAnswered = q.jawabanTersimpan != null && 
            (q.jawabanTersimpan?.idOpsiDipilih != null || !q.jawabanTersimpan?.jawabanTeks.isNullOrBlank())
        val isRagu = q.jawabanTersimpan != null && q.jawabanTersimpan?.isRagu == 1
        val isCurrent = (position == currentIndex)

        val drawable = GradientDrawable().apply {
            cornerRadius = 14f * context.resources.displayMetrics.density
            
            when {
                isRagu -> {
                    // KUNING (Ragu-ragu)
                    setColor(ContextCompat.getColor(context, R.color.lumina_gold))
                    if (isCurrent) setStroke((3 * context.resources.displayMetrics.density).toInt(), ContextCompat.getColor(context, R.color.lumina_navy))
                }
                isAnswered -> {
                    // HIJAU (Sudah Diisi)
                    setColor(ContextCompat.getColor(context, R.color.status_safe))
                    if (isCurrent) setStroke((3 * context.resources.displayMetrics.density).toInt(), ContextCompat.getColor(context, R.color.lumina_navy))
                }
                else -> {
                    // PUTIH (Belum Diisi)
                    setColor(Color.WHITE)
                    if (isCurrent) {
                        setStroke((3 * context.resources.displayMetrics.density).toInt(), ContextCompat.getColor(context, R.color.lumina_teal))
                    } else {
                        setStroke((1.5f * context.resources.displayMetrics.density).toInt(), Color.parseColor("#CBD5E1"))
                    }
                }
            }
        }

        holder.tvNumber.background = drawable

        if (isRagu || isAnswered) {
            holder.tvNumber.setTextColor(Color.WHITE)
        } else {
            if (isCurrent) {
                holder.tvNumber.setTextColor(ContextCompat.getColor(context, R.color.lumina_teal))
            } else {
                holder.tvNumber.setTextColor(ContextCompat.getColor(context, R.color.text_primary))
            }
        }

        holder.itemView.setOnClickListener {
            onItemClick(position)
        }
    }

    override fun getItemCount(): Int = questions.size

    fun setCurrentIndex(index: Int) {
        this.currentIndex = index
        notifyDataSetChanged()
    }
}
