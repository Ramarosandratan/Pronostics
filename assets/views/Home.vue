<template>
  <div class="home-page">
    <header class="page-header">
      <h1>Courses Récentes</h1>
      <p class="subtitle">Sélectionnez une course pour consulter les pronostics et indices de confiance de l'algorithme.</p>
    </header>

    <div v-if="loading" class="loader-container">
      <div class="spinner"></div>
      <p>Chargement des courses...</p>
    </div>

    <div v-else-if="error" class="error-container">
      <p>{{ error }}</p>
    </div>

    <div v-else class="races-grid">
      <router-link 
        v-for="race in races" 
        :key="race.id" 
        :to="'/race/' + race.id"
        class="race-card"
      >
        <div class="race-card-header">
          <span class="race-date">{{ formatDate(race.race_date) }}</span>
          <span class="race-badge" :class="getDisciplineClass(race.discipline)">
            {{ race.discipline || 'Mixte' }}
          </span>
        </div>
        
        <div class="race-card-body">
          <h2 class="hippodrome-name">{{ race.hippodrome }}</h2>
          <div class="race-meta">
            <span class="meta-item">
              <strong>R{{ race.meeting_number }}</strong>C{{ race.race_number }}
            </span>
            <span class="meta-item" v-if="race.time">
              🕒 {{ race.time }}
            </span>
            <span class="meta-item" v-if="race.distance">
              📏 {{ race.distance }}m
            </span>
          </div>
        </div>

        <div class="race-card-footer">
          <span class="analyze-btn">Analyser la course →</span>
        </div>
      </router-link>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue';

export default {
  name: 'Home',
  setup() {
    const races = ref([]);
    const loading = ref(true);
    const error = ref(null);

    const fetchRaces = async () => {
      try {
        const response = await fetch('/api/races/recent');
        if (!response.ok) throw new Error('Erreur lors du chargement des courses');
        races.value = await response.json();
      } catch (e) {
        error.value = e.message;
      } finally {
        loading.value = false;
      }
    };

    const formatDate = (dateStr) => {
      if (!dateStr) return 'Date inconnue';
      const d = new Date(dateStr);
      return d.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' });
    };

    const getDisciplineClass = (discipline) => {
      if (!discipline) return 'badge-default';
      const d = discipline.toLowerCase();
      if (d.includes('attel')) return 'badge-attele';
      if (d.includes('mont')) return 'badge-monte';
      if (d.includes('plat')) return 'badge-plat';
      if (d.includes('obst') || d.includes('haies')) return 'badge-obstacle';
      return 'badge-default';
    };

    onMounted(() => {
      fetchRaces();
    });

    return {
      races,
      loading,
      error,
      formatDate,
      getDisciplineClass
    };
  }
};
</script>
