<template>
  <div class="race-details-page">
    <div class="action-bar">
      <router-link to="/" class="btn-back">← Retour aux courses</router-link>
      <div class="mode-selector">
        <label for="pronostic-mode">Mode de prédiction:</label>
        <select id="pronostic-mode" v-model="mode" @change="fetchPronostic">
          <option value="conservative">Conservateur</option>
          <option value="aggressive">Agressif</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="loader-container">
      <div class="spinner"></div>
      <p>Analyse de la course en cours...</p>
    </div>

    <div v-else-if="error" class="error-container">
      <p>{{ error }}</p>
    </div>

    <div v-else-if="data" class="content">
      <header class="race-header">
        <div class="race-header-main">
          <h1>{{ data.race.hippodrome }} - R{{ data.race.meeting_number }}C{{ data.race.race_number }}</h1>
          <span class="date">{{ data.race.race_date }}</span>
        </div>
        <div class="race-header-meta">
          <span class="badge">{{ data.race.discipline || 'Mixte' }}</span>
          <span class="participants">🐎 {{ data.count }} partants</span>
        </div>
      </header>

      <section class="trust-section">
        <h2>Indice de confiance</h2>
        <div class="confidence-bar-bg">
          <div class="confidence-bar-fill" :style="{ width: confidenceScore + '%' }" :class="confidenceColorClass"></div>
        </div>
        <p class="confidence-text">{{ confidenceScore }}% - {{ confidenceLabel }}</p>
      </section>

      <section class="rankings-section">
        <h2>Top 5 Pronostic</h2>
        <div class="rankings-list">
          <div v-for="(horse, index) in data.top" :key="horse.horse_id" class="ranking-item" :style="{ animationDelay: (index * 0.1) + 's' }">
            <div class="rank-number" :class="'rank-' + (index + 1)">{{ index + 1 }}</div>
            
            <div class="horse-info">
              <div class="horse-name-row">
                <span class="saddle-badge">{{ horse.saddle_number ? '#' + horse.saddle_number : '-' }}</span>
                <span class="horse-name">{{ horse.horse_name }}</span>
              </div>
            </div>

            <div class="probability">
              <div class="prob-header">
                <span>Probabilité de victoire</span>
                <span class="prob-value">{{ formatProb(horse.win_probability) }}%</span>
              </div>
              <div class="prob-bar-bg">
                <div class="prob-bar-fill" :style="{ width: formatProb(horse.win_probability) + '%' }"></div>
              </div>
            </div>
            
            <div class="score-badge">
              {{ Number(horse.raw_score ?? horse.score ?? 0).toFixed(1) }} pts
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script>
import { ref, onMounted, computed } from 'vue';
import { useRoute } from 'vue-router';

export default {
  name: 'RaceDetails',
  setup() {
    const route = useRoute();
    const data = ref(null);
    const loading = ref(true);
    const error = ref(null);
    const mode = ref('conservative');

    const fetchPronostic = async () => {
      loading.value = true;
      error.value = null;
      try {
        const response = await fetch(`/pronostic/${route.params.id}?mode=${mode.value}`);
        if (!response.ok) throw new Error('Erreur de récupération du pronostic.');
        data.value = await response.json();
      } catch (e) {
        error.value = e.message;
      } finally {
        loading.value = false;
      }
    };

    onMounted(() => {
      fetchPronostic();
    });

    const formatProb = (prob) => {
      const numeric = Number(prob || 0);
      if (numeric <= 0) return 0;
      if (numeric <= 1) return Number((numeric * 100).toFixed(1));

      return Number(numeric.toFixed(1));
    };

    const topSumProb = computed(() => {
      if (!data.value || !data.value.top) return 0;
      return data.value.top.reduce((sum, h) => sum + formatProb(h.win_probability), 0);
    });

    const confidenceScore = computed(() => {
      if (data.value && data.value.confidence && typeof data.value.confidence.percent === 'number') {
        return Math.round(data.value.confidence.percent);
      }

      const normalized = Math.min(Math.round(topSumProb.value * 1.2), 99);

      return normalized || 50;
    });

    const confidenceColorClass = computed(() => {
      const score = confidenceScore.value;
      if (score >= 80) return 'color-high';
      if (score >= 60) return 'color-medium';
      return 'color-low';
    });

    const confidenceLabel = computed(() => {
      const score = confidenceScore.value;
      if (score >= 80) return 'Excellente (Pronostic très clair)';
      if (score >= 60) return 'Moyenne (Course ouverte)';
      return 'Faible (Course imprévisible)';
    });

    return {
      data,
      loading,
      error,
      mode,
      fetchPronostic,
      formatProb,
      confidenceScore,
      confidenceColorClass,
      confidenceLabel
    };
  }
};
</script>
